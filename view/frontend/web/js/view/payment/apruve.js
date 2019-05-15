define([
    'jquery',
], function ($) {
    var s = window.checkoutConfig.payment.apruve.js_endpoint + '/js/v4/apruve.js';

    var load = function () {
        require.undef(s);

        var poNumber = $('#po_number').val()
        var url = window.checkoutConfig.payment.apruve.hash_reload;
        var order = window.checkoutConfig.payment.apruve.order;

        if (typeof order !== "string") {
            order = JSON.stringify(order);
        }

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
        window.apruve = null;
        $.getScript(s, function () {
            var merchantId = window.checkoutConfig.payment.apruve.merchant_id;
            var order = window.checkoutConfig.payment.apruve.order;
            var secureHash = window.checkoutConfig.payment.apruve.secure_hash;

            window.apruve.setOrder(order, secureHash);
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
