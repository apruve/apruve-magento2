<?php

namespace Apruve\Payment\Controller\Data;

class Index extends \Magento\Framework\App\Action\Action
{
    const DISCOUNT = 'Discount';

    protected $order;
    protected $request;
    protected $quote;
    protected $helper;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Checkout\Model\Cart $cart,
        \Apruve\Payment\Helper\Data $helper
    ) {
        $this->request = $request;
        $this->helper = $helper;
        $this->quote = $cart->getQuote();

        parent::__construct($context);
    }

    public function execute()
    {
        $order = json_decode($this->request->getParam('order'), 1);
        $quote = $this->quote;

        $poNumber = $this->request->getParam('poNumber');
        $totals = $quote->getTotals();

        if (!empty($poNumber) && $poNumber != 'undefined') {
            $order['po_number'] = $poNumber;
        } else {
            if (isset($order['po_number'])) {
                unset($order['po_number']);
            }
        }

        $order['amount_cents'] = $totals['grand_total']->getValue() * 100;
        $order['tax_cents'] = $totals['tax']->getValue() * 100;
        $order['shipping_cents'] = $quote->getShippingAddress()->getShippingAmount() * 100;

        /*Discount Item*/
        $discount = $quote->getSubtotal() - $quote->getSubtotalWithDiscount();

        if ($discount > 0) {
            //Add Discount item
            $discountItem = [];
            $discountItem['title'] = self::DISCOUNT;
            $discountItem['amount_cents'] = (int)($discount * -100);
            $discountItem['price_ea_cents'] = (int)($discount * -100);
            $discountItem['quantity'] = 1;
            $discountItem['description'] = self::DISCOUNT;
            $discountItem['sku'] = self::DISCOUNT;
            $discountItem['variant_info'] = '';
            $discountItem['view_product_url'] = $this->helper->getStoreUrl();

            $hasDiscountItem = false;
            foreach ($order['line_items'] as $k => $item) {
                if ($item['title'] == self::DISCOUNT) {
                    $hasDiscountItem = true;
                }
            }

            if (!$hasDiscountItem) {
                $order['line_items'][] = $discountItem;
            }
        } else {
            // Remove Discount item
            foreach ($order['line_items'] as $k => $item) {
                if ($item['title'] == self::DISCOUNT) {
                    unset($order['line_items'][$k]);
                }
            }
        }

        $this->order = $order;
        $data = [
            'order' => json_encode($this->order),
            'secure_hash' => $this->_getSecureHash()
        ];

        echo json_encode($data);
    }

    protected function _getSecureHash()
    {
        $order = $this->order;
        $concatString = $this->helper->getApiKey();

        foreach ($order as $val) {
            if (!is_array($val)) {
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
