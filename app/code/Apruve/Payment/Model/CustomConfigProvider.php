<?php

namespace Apruve\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;

class CustomConfigProvider implements ConfigProviderInterface
{
    const CODE = 'apruve';
    protected $method;
    protected $storeManager;

    public function __construct(
        \Magento\Payment\Helper\Data $paymentHelper,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Catalog\Helper\Product $catalogProductHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->catalogProductHelper = $catalogProductHelper;
        $this->method               = $paymentHelper->getMethodInstance(self::CODE);
        $this->cart                 = $cart;
        $this->api                  = $cart;
        $this->storeManager         = $storeManager;
        $this->order                = $this->_getOrderData();
    }

    protected function _getOrderData()
    {
        $order = [];
        $quote = $this->_getQuote();
        $quote->reserveOrderId();
        $totals = $quote->getTotals();

        $order['merchant_id']       = $this->_getMerchantId();
        $order['merchant_order_id'] = $quote->getReservedOrderId();
        $order['amount_cents']      = $totals['grand_total']->getValue() * 100;
        $order['currency']          = 'USD';
        $order['tax_cents']         = $totals['tax']->getValue() * 100;
        $order['shipping_cents']    = $quote->getShippingAddress()->getShippingAmount() * 100;
        $order['line_items']        = $this->_getOrderItems($quote);

        //$order['finalize_on_create'] = 'false';

        return $order;
    }

    protected function _getQuote()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $state         = $objectManager->get('Magento\Framework\App\State');

        if ($state->getAreaCode() == 'adminhtml') {
            $cart         = $objectManager->get('Magento\Backend\Model\Session\Quote');
            $adminQuote   = $cart->getQuote();
            $quoteFactory = $objectManager->create('Magento\Quote\Model\QuoteFactory');
            $quote        = $quoteFactory->create()->loadByIdWithoutStore($adminQuote->getId());
        } else {
            $quote = $this->cart->getQuote();
        }

        return $quote;
    }

    protected function _getMerchantId()
    {
        return $this->method->getConfigData('merchant_id');
    }

    protected function _getOrderItems($quote)
    {
        $items = [];

        foreach ($quote->getAllVisibleItems() as $k => $item) {
            $items[ $k ]['title']            = $item['name'];
            $items[ $k ]['amount_cents']     = $item['row_total'] * 100;
            $items[ $k ]['price_ea_cents']   = $item['price'] * 100;
            $items[ $k ]['quantity']         = $item['qty'];
            $items[ $k ]['description']      = $item['name'];
            $items[ $k ]['sku']              = $item['sku'];
            $items[ $k ]['variant_info']     = '';
            $items[ $k ]['view_product_url'] = $this->catalogProductHelper->getProductUrl($item['product_id']);
        }

        return $items;
    }

    public function getConfigJson()
    {
        $config = $this->getConfig();
        if ($config) {
            return json_encode($this->getConfig());
        }

        return false;
    }

    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'api_key'     => $this->_getApiKey(),
                    'merchant_id' => $this->_getMerchantId(),
                    'order'       => $this->_getOrder(),
                    'js_endpoint' => $this->_getJSEndpoint(),
                    'secure_hash' => '',
                    'hash_reload' => $this->storeManager->getStore()->getUrl('apruve/data/index')
                ]
            ]
        ];
    }

    protected function _getApiKey()
    {
        return $this->method->getConfigData('api_key');
    }

    protected function _getOrder()
    {
        return json_encode($this->order);
    }

    protected function _getJSEndpoint()
    {
        return '//' . $this->method->getConfigData('mode') . '.apruve.com';
    }

    protected function _getSecureHash()
    {
        $order = $this->order;

        $concatString = $this->_getApiKey();
        foreach ($order as $val) {
            if (! is_array($val)) {
                $concatString .= $val;
            } else {
                foreach ($val as $v) {
                    foreach ($v as $s) {
                        $concatString .= $s;
                    }
                }
            }
        }

        return hash('sha256', $concatString);
    }
}
