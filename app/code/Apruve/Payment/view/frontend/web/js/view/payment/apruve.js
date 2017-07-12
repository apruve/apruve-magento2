var apruve;

define([
    'jquery',
], function ($) {
    var s = window.checkoutConfig.payment.apruve.js_endpoint + '/js/v4/apruve.js?display=compact';

    var load = function () {
        require.undef(s);

        var poNumber = $('#po_number').val()
        var url = window.checkoutConfig.payment.apruve.hash_reload;
        var order = window.checkoutConfig.payment.apruve.order;

        jQuery.ajax({
            url: url,
            data: 'order=' + order + '&poNumber=' + poNumber,
            dataType: 'json',
            success: function (data) {
                window.checkoutConfig.payment.apruve.order = data.order;
                window.checkoutConfig.payment.apruve.secure_hash = data.secure_hash;
                initialize();
            }
        });
    }

    var initialize = function () {
        $.getScript(s, function () {
            var merchantId = window.checkoutConfig.payment.apruve.merchant_id;
            var order = JSON.parse(window.checkoutConfig.payment.apruve.order);
            var secureHash = window.checkoutConfig.payment.apruve.secure_hash;

            apruve.setOrder(order, secureHash);
            apruve.registerApruveCallback(apruve.APRUVE_COMPLETE_EVENT, function (orderId) {
                $('#apruve-order-id').val(orderId)
                $('.apruve-checkout').addClass('visible');
            });

            $('.loading-mask').hide();
            preparePONumber();
        });
    }

    var preparePONumber = function () {
        // PO Number change dynamically
        if (!$('#po_number').hasClass('apruve')) {
            $('body').on('change', '#po_number', function () {
                load()
            });
            $('#po_number').addClass('apruve');
        }
    }

    return {
        initialize: initialize,
        load: load
    }
});