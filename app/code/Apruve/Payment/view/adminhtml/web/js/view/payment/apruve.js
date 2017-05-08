define([
    "jquery",
],function($){
    var initialize = function() {
        var jSPath = '/js/v4/apruve.js';
        var jSEndpoint = config.payment.apruve.js_endpoint;
        var url = jSEndpoint + jSPath;
        
        /* Asynchronously load Apruve JS Library */
        var initApruve = function(url, callback) {
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

        /* Init Payment */
        function initPayment() {
            var merchantId = config.payment.apruve.merchant_id;
            var order = JSON.parse(config.payment.apruve.order);
            var secureHash = config.payment.apruve.secure_hash;

            apruve.setOrder(order, secureHash);
                    
            apruve.registerApruveCallback(apruve.APRUVE_LAUNCHED_EVENT, function () {
            });

            apruve.registerApruveCallback(apruve.APRUVE_CLOSED_EVENT, function () {
            });
            
            apruve.registerApruveCallback(apruve.APRUVE_COMPLETE_EVENT, function (orderId) {
                jQuery('#apruve-order-id').val(orderId)
            });

//            console.log(apruve.errors);
        };
    };

    return {
        initialize: initialize
    }
});
