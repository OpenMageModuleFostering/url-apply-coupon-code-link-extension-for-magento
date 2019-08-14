<?php

/**
 * @category    MP
 * @package     MP_AutoApplyCoupon
 * @copyright   MagePhobia (http://www.magephobia.com)
 */
class MP_AutoApplyCoupon_Model_Observer
{
    public function controllerActionPredispatch(Varien_Event_Observer $observer)
    {
        $params = array_change_key_case(Mage::app()->getRequest()->getParams(), CASE_LOWER);
        $cookie = Mage::getSingleton('core/cookie');

        if (isset($params['quote_id'])) {
            Mage::getSingleton('core/session')->setDoNotShowDiscountPopup(1);
            $quote = Mage::getModel('sales/quote')->load(intval($params['quote_id']));
            if (is_object($quote) && $quote->getId() && $quote->getIsActive()) {
                if (Mage::getSingleton('customer/session')->isLoggedIn() && $quote->getCustomerId()) {
                    if ($quote->getCustomerId() != Mage::getSingleton('customer/session')->getCustomer()->getId()) {
                        Mage::getSingleton('customer/session')->logout();
                        Mage::app()->getResponse()
                            ->setRedirect('/customer/account/login', 301)
                            ->sendResponse();
                    } else {
                        Mage::getSingleton('checkout/session')->setQuoteId($quote->getId());
                    }
                } elseif (Mage::getSingleton('customer/session')->isLoggedIn() && !$quote->getCustomerId()) {
                    Mage::getSingleton('checkout/session')->setQuoteId($quote->getId());
                } elseif (!Mage::getSingleton('customer/session')->isLoggedIn()) {
                    if ($quote->getCustomerId()) {
                        Mage::app()->getResponse()
                            ->setRedirect('/customer/account/login', 301)
                            ->sendResponse();
                    } else {
                        Mage::getSingleton('checkout/session')->setQuoteId($quote->getId());
                    }
                }
            } else {
                Mage::getSingleton('core/session')->addNotice('Sorry, looks like you\'ve got invalid Invalid Cart'
                    . ' ID or you have already made a purchase with the cart you are trying to access. Thank you!');
            }
        }

        $discountEmail = '';
        if (array_key_exists('discount-email', $params) !== false) {
            $discountEmail = $params['discount-email'];
        } elseif (array_key_exists('email', $params) !== false) {
            $discountEmail = $params['email'];
        } elseif (array_key_exists('utm_email', $params) !== false) {
            $discountEmail = $params['utm_email'];
        }
        if (isset($discountEmail)) {
            if ($discountEmail != '') {
                if (Zend_Validate::is($discountEmail, 'EmailAddress')) {
                    $cookie = Mage::getSingleton('core/cookie');
                    if (Mage::helper('checkout/cart')->getItemsCount()) {
                        Mage::getSingleton('checkout/session')
                            ->getQuote()
                            ->setCustomerEmail($discountEmail)
                            ->save();
                        $cookie->delete('discount-email');
                    } else {
                        $cookie->set('discount-email', $discountEmail, time() + 86400, '/');
                    }
                }
            }
        }

        if (isset($params['coupon']) || (isset($params['utm_promocode']))) {

            if (isset($params['coupon'])) {
                $coupon = $params['coupon'];
            }

            if (isset($params['utm_promocode'])) {
                $coupon = $params['utm_promocode'];
            }

            if ($coupon != '') {
                if ($this->_isCouponValid($coupon)) {
                    if (Mage::helper('checkout/cart')->getItemsCount()) {
                        Mage::getSingleton('checkout/session')
                            ->getQuote()
                            ->setCouponCode($coupon)
                            ->save();
                        $cookie->delete('discount_code');
                    } else {
                        $cookie->set('discount_code', $coupon, time() + 86400, '/');
                    }
                }
            }
        } else {
            $this->checkoutCartAddProductComplete();
        }
    }

    public function checkoutCartAddProductComplete()
    {
        $cookie = Mage::getSingleton('core/cookie');
        $coupon = $cookie->get('discount_code');
        if (($coupon) && ($this->_isCouponValid($coupon)) && (Mage::helper('checkout/cart')->getItemsCount())) {
            Mage::getSingleton('checkout/session')->getQuote()->setCouponCode($coupon)->save();
            $cookie->delete('discount_code');
        }

        $email = $cookie->get('discount-email');
        if ($email && Zend_Validate::is($email, 'EmailAddress')) {
            if (Mage::helper('checkout/cart')->getItemsCount()) {
                Mage::getSingleton('checkout/session')->getQuote()->setCustomerEmail($email)->save();
                $cookie->delete('discount-email');
            }
        }
    }

    protected function _isCouponValid($couponCode)
    {
        try {
            $coupon = Mage::getModel('salesrule/coupon')->load($couponCode, 'code');
            if (is_object($coupon)) {
                $rule = Mage::getModel('salesrule/rule')->load($coupon->getRuleId());
                if (is_object($rule)) {
                    $conditionsUnSerialized = unserialize($rule->getConditionsSerialized());
                    if ($rule->getIsActive()) {
                        if (is_array($conditionsUnSerialized) && (isset($conditionsUnSerialized['conditions']))
                            && (is_array($conditionsUnSerialized['conditions']))
                        ) {
                            foreach ($conditionsUnSerialized['conditions'] as $condition) {
                                if (isset($condition['attribute']) && ($condition['attribute'] == 'base_subtotal')
                                    && (isset($condition['operator'])) && ($condition['operator'] == '>=')
                                    && (isset($condition['value'])) && ($condition['value'] > 0)
                                    && (Mage::getSingleton('checkout/session')
                                            ->getQuote()->getSubtotal() < $condition['value'])
                                ) {
                                    $cookie = Mage::getSingleton('core/cookie');
                                    $cookie->set('discount_code', $couponCode, time() + 86400, '/');
                                    return false;
                                }
                            }
                        }

                        return true;
                    } else {
                        return false;
                    }
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }
}