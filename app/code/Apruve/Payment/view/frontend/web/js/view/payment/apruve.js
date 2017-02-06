var apruve;
jQuery(function() {
     require(['Apruve_Payment/js/view/payment/apruve'],function(apruveData){
         apruveData.initialize();
     });
});

jQuery('#apruve').on('click', function() {
     require(['Apruve_Payment/js/view/payment/apruve'],function(apruveData){
         apruveData.initialize();
     });
});

define([
    "jquery",
], function($){
    $('body').on('change', '#po_number', function () {hashReload()});
    
    var hashReload = function() {
        var poNumber = $('#po_number').val()
        var url = window.checkoutConfig.payment.apruve.hash_reload;
        var order = window.checkoutConfig.payment.apruve.order;
        
        jQuery.ajax({
            url: url,
            data: 'order='+order+'&poNumber='+poNumber,
            dataType: 'json',
            success: function(data) {
                window.checkoutConfig.payment.apruve.order = data.order;
                window.checkoutConfig.payment.apruve.secure_hash = data.secure_hash;
                initPayment();
            }
        });
    }
    
    var initialize = function() {
        if ($('.apruveBtn').length >= 1) return;
        
        var jSPath = '/js/v4/apruve.js?display=compact';
        var jSEndpoint = window.checkoutConfig.payment.apruve.js_endpoint;
        var url = jSEndpoint + jSPath;
    
        /* Asynchronously load Apruve JS Library */
        function initApruve(url, callback) {
            jQuery(document.body).on('change', "input[name*='payment']", function() {
                if (!(jQuery('.apruveWrap').length > 0)) {
                    apruve = undefined;
                    initApruve(url,
                        function () {
                            initPayment();
                        }
                    );
                }
            });
            
            var script = document.createElement("script");
            script.type = "text/javascript";
            if (script.readyState) {
                script.onreadystatechange = function () {
                    if (script.readyState == "loaded" || script.readyState == "complete") {
                        script.onreadystatechange = null;
                        callback()
                    }
                }
            } else {
                script.onreadystatechange = callback;
                script.onload = callback
            }
            script.src = url;
            document.body.appendChild(script)
        }
    
        if (typeof ApruvePayment === 'undefined') {
            initApruve(url,
                function () {
                    initPayment();
                }
            );
        } else {
            initPayment();
        }
    }

    /* Init Payment */
    function initPayment() {
        var merchantId = window.checkoutConfig.payment.apruve.merchant_id;
        var order = JSON.parse(window.checkoutConfig.payment.apruve.order);
        var secureHash = window.checkoutConfig.payment.apruve.secure_hash;

        apruve.setOrder(order, secureHash);
                
        apruve.registerApruveCallback(apruve.APRUVE_LAUNCHED_EVENT, function () {
        });

        apruve.registerApruveCallback(apruve.APRUVE_CLOSED_EVENT, function () {
        });
        
        apruve.registerApruveCallback(apruve.APRUVE_COMPLETE_EVENT, function (orderId) {
            $('#apruve-order-id').val(orderId)
            $('.apruve-checkout').addClass('visible');
        });
        
        console.log(apruve.errors);
    }
    
    return {
        initialize: initialize
    }
});