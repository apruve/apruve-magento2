define(
    [
        'Apruve_Payment/js/view/payment/apruve',
        'Magento_Checkout/js/view/payment/default',
    ],
    function (Apruve, Component) {
        'use strict';
 
        return Component.extend({
            defaults: {
                template: 'Apruve_Payment/payment/apruve'
            },
            
            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'apruve_order_id': this.getApruveOrderId(),
                    }
                };
            },

            initialize: function () {
                this._super();
                Apruve.load();
            },
            
            getMailingAddress: function () {
                return window.checkoutConfig.payment.checkmo.mailingAddress;
            },

            getApiKey: function () {
                return window.checkoutConfig.payment.apruve.api_key;
            },

            getMerchantId: function () {
                return window.checkoutConfig.payment.apruve.merchant_id;
            },
            
            getApruveOrderId: function () {
                return jQuery('#apruve-order-id').val();
            },

            placeOrder: function() {
                var self = this;
                var context = this._super;
                var default_arguments = arguments;

                var apruve = window.apruve;

                apruve.startCheckout();

                apruve.registerApruveCallback(apruve.APRUVE_COMPLETE_EVENT, function (orderId) {
                    jQuery('#apruve-order-id').val(orderId);
                    context.apply(self, default_arguments);
                });
            }
        });
    }
);
