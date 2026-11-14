define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader'
], function (Component, fullScreenLoader) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'ArionPay_Payment/payment/arionpay'
        },

        // The order is placed first ("Pending Payment"), then the customer goes to the ArionPay payment page.
        redirectAfterPlaceOrder: false,

        isTestMode: function () {
            var c = window.checkoutConfig.payment.arionpay || {};
            return !!c.testMode;
        },

        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();
            window.location.replace(window.checkoutConfig.payment.arionpay.redirectUrl);
        }
    });
});
