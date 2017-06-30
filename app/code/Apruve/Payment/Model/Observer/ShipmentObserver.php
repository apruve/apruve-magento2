<?php
namespace Apruve\Payment\Model\Observer;

use Magento\Framework\Event\ObserverInterface;

class ShipmentObserver implements ObserverInterface
{
    const CODE = 'apruve';
    const DISCOUNT = 'Discount';
    const SHIPPING_PARTIAL  = 'partial';
    const SHIPPING_COMPLETE = 'fulfilled';

    protected $_method;
    protected $_helper;
    protected $_order;
    protected $_shipment;
    protected $_firstShipment;

    public function __construct(
        \Magento\Payment\Helper\Data $paymentHelper,
        \Apruve\Payment\Helper\Data $helper
    ) {
        $this->_method = $paymentHelper->getMethodInstance(self::CODE);
        $this->_helper = $helper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer) {
        $this->_shipment = $observer->getEvent()->getShipment();
        $this->_order = $this->_shipment->getOrder();

        if ($this->_order->getShipmentsCollection()->getSize() <= 1) {
            $this->_firstShipment = 1;
        } else {
            $this->_firstShipment = 0;
        }


        if($this->_order->getPayment()->getMethod() != self::CODE) {
            return;
        }

        /**Validate*/
        if (!($this->_order->getInvoiceCollection()->getSize())) {
           throw new \Magento\Framework\Validator\Exception(__('Please create invoice before shipping.'));
        }

        /**Create Shipment*/
        $invoice = $this->_order->getInvoiceCollection()->getFirstItem();
        $token = $invoice->getTransactionId();
        $tracks = $this->_shipment->getAllTracks();
        $track = end($tracks);
        $amount = 0;
        $tax = 0;

        foreach ($this->_shipment->getAllItems() as $item) {
            $amount += $item->getPrice();
            $tax += ($item->getPriceInclTax() - $item->getPrice());
        }

        $data = array();

        // First shipment holds complete amounts of tax_cents and shipping_cents
        if ($this->_firstShipment) {
            $data['tax_cents'] = $this->_order->getTaxAmount() * 100;
            $data['shipping_cents'] = $this->_order->getShippingAmount() * 100;
        } else {
            $data['tax_cents'] = 0;
            $data['shipping_cents'] = 0;
        }

        $data['invoice_id'] = $this->_getInvoiceId($this->_order);
        $data['amount_cents'] = $this->_getPartialShipmentAmount() * 100;
        $data['currency'] = $this->_order->getData('base_currency_code');
        $data['shipper'] = $track ? $track->getTitle() : '';
        $data['tracking_number'] = $track ? $track->getTrackNumber() : '';
        $data['shipped_at'] = date(DATE_ISO8601, strtotime($this->_shipment->getCreatedAt()));
        $data['delivered_at'] = '';
        $data['merchant_notes'] = $this->_shipment->getCustomerNote();
        $data['status'] = $this->_getShipmentStatus();
        $data['merchant_shipment_id'] = $this->_shipment->getIncrementId();
        $data['shipment_items'] = $this->_getShipmentItems($this->_shipment);

        $response = $this->_shipment($token, json_encode($data));
        if (!isset($response->id)) {
            $errorMessage = isset($response->errors) ? $response->errors[0]->title : 'Error Creating Shipment';
            throw new \Magento\Framework\Validator\Exception(__($errorMessage));
        }
    }

    protected function _getInvoiceId() {
        $invoices = $this->_order->getInvoiceCollection();
        if ($invoices->getSize() < 1) {
            throw new \Magento\Framework\Validator\Exception(__('Please Invoice Order Before Shipping.'));
        }

        $invoice = $invoices->getFirstItem();
        return $invoice->getTransactionId();
    }

    protected function _getShipmentItems() {
        $data = array();

        $items = $this->_shipment->getAllItems();
        foreach ($items as $item) {
            $product = $item->getOrderItem()->getProduct();
            $shipmentItem = array();

            $shipmentItem['price_ea_cents'] = $item->getPrice() * 100;
            $shipmentItem['quantity'] = $item->getQty();
            $shipmentItem['price_total_cents'] = $item->getPrice() * $item->getQty() * 100;
            $shipmentItem['currency'] = $this->_shipment->getOrder()->getData('order_currency_code');
            $shipmentItem['title'] = $product->getName();
            $shipmentItem['merchant_notes'] = '';
            $shipmentItem['description'] = $product->getMetaDescription();
            $shipmentItem['sku'] = $item->getSku();
            $shipmentItem['variant_info'] = '';
            $shipmentItem['vendor'] = '';
            $shipmentItem['view_product_url'] = $product->getProductUrl();

            $data[] = $shipmentItem;
        }

        /**Add Discount Item*/
        $discount = $this->_shipment->getOrder()->getDiscountAmount();
        if ($discount < 0 && $this->_firstShipment) {
            $discountItem = [];
            $discountItem['price_ea_cents'] = (int)($discount * 100);
            $discountItem['quantity'] = 1;
            $discountItem['price_total_cents'] = (int)($discount * 100);
            $discountItem['currency'] = $this->_shipment->getOrder()->getData('order_currency_code');
            $discountItem['title'] = self::DISCOUNT;
            $discountItem['merchant_notes'] = '';
            $discountItem['description'] = self::DISCOUNT;
            $discountItem['sku'] = self::DISCOUNT;
            $discountItem['variant_info'] = '';
            $discountItem['vendor'] = '';
            $discountItem['view_product_url'] = $this->_helper->getStoreUrl();

            $data[] = $discountItem;
        }

        return $data;
    }

    protected function _getShipmentStatus() {
        $qtyOrdered = $this->_order->getTotalQtyOrdered();
        $qtyShipped = $this->_shipment->getTotalQty();

        if ($qtyOrdered > $qtyShipped) {
            return self::SHIPPING_PARTIAL;
        }

        return self::SHIPPING_COMPLETE;
    }

    protected function _getPartialShipmentAmount() {
        $totalsAmount = $this->_order->getGrandTotal() - $this->_order->getSubtotal();
        $amount = 0;

        foreach ($this->_shipment->getAllItems() as $item) {
            $amount += $item->getPrice() * $item->getQty();
        }

        if ($this->_firstShipment) {
            return $amount + $totalsAmount;
        }

        return $amount;
    }

    protected function _shipment($token, $data = '') {
        $apiKey = $this->_method->getConfigData('api_key');
        $mode = $this->_method->getConfigData('mode');
        $url = sprintf("https://%s.apruve.com/api/v4/invoices/%s/shipments", $mode, $token);
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => array(
                'accept: application/json',
                'apruve-api-key: ' . $apiKey,
                'content-type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpStatus != 404) {
            return json_decode($response);
        }

        return false;
    }
}
