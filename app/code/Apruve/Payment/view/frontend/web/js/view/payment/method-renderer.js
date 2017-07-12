define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component,
              rendererList) {
        'use strict';
        rendererList.push(
            {
                type: 'apruve',
                component: 'Apruve_Payment/js/view/payment/method-renderer/apruve'
            }
        );
        return Component.extend({});
    }
);
