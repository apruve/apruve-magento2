# apruve-magento2
Apruve Magento 2 Integration

This project contains a basic integration between Magento 2 and Apruve. This integration adds Apruve as a payment method in Magento, which will allow your customers to pay with their Apruve line of credit. 

This plug-in is currently being reviewed for submission into the Magento Marketplace. You can use this project to install the integration manually by copying the files onto your Magento server, or use it as a base for a custom integration.

The file structure follows Magento's file structure, so if you copy the files into the Magento root folder they will be placed into the correct locations.


**Important: Before you begin to make any changes please make sure that you have backed up your currently Magento installation!**

**Installation Steps**

* Copy the files from this repository into your Magento installation.

* Run "magento setup:upgrade" from your Magento directory.

* Run "magento setup:di:compile".

* Run "magento setup:static-content:deploy".

* Run "magento cache:clean" or clear the cache from the Magento admin console. 

**Configuration Steps**

* In the Magento admin console, go to Stores > Configuration > Sales > Payment Methods. Scroll down to Other Payment Methods > Apruve. 

* Enable the plugin by selecting "Yes" from the drop-down.

* Enter your Apruve Merchant ID (found in the Settings section of your Apruve account).

* Enter your Apruve API Key (also found in Settings > Technical).

* Copy the supplied Webhook URL into your Apruve Settings > Technical > Webhook Endpoint section and save.

* Save your Magento config and clear the cache again.

That's it! Apruve should now be available as a payment method in the checkout process. 