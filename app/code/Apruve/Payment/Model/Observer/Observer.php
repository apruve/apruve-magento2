<?php
namespace Apruve\Payment\Model\Observer;

use Magento\Framework\Event\ObserverInterface;

class Observer implements ObserverInterface
{   
    const CODE = 'apruve';
    const SHIPPING_PARTIAL  = 'partial';
    const SHIPPING_COMPLETE = 'fulfilled';

    protected $_method;
    
    public function __construct(
        \Magento\Payment\Helper\Data $paymentHelper
    ) {
        $this->_method = $paymentHelper->getMethodInstance(self::CODE);
    }
    
    public function execute(\Magento\Framework\Event\Observer $observer) {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        if($order->getPayment()->getMethod() != self::CODE) {
            return;
        }
        
        /**Validate*/
        if (!($order->getInvoiceCollection()->getSize())) {
           throw new \Magento\Framework\Validator\Exception(__('Please create invoice before shipping.'));
        }
        
        /**Create Shipment*/
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $token = $invoice->getTransactionId();
        $tracks = $shipment->getAllTracks();
        $track = end($tracks);
        $amount = 0;
        $tax = 0;

        foreach ($shipment->getAllItems() as $item) {
            $amount += $item->getPrice();
            $tax += ($item->getPriceInclTax() - $item->getPrice()); 
        }
        
        $data = array();
        $data['invoice_id'] = $this->_getInvoiceId($order);
        $data['amount_cents'] = $amount * 100;
        $data['tax_cents'] = $tax * 100;
        $data['shipping_cents'] = $order->getShippingAmount() * 100;
        $data['currency'] = $order->getData('base_currency_code');
        $data['shipper'] = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();
        $data['tracking_number'] = $track ? $track->getTrackNumber() : '';
        $data['shipped_at'] = date(DATE_ISO8601, strtotime($shipment->getCreatedAt()));
        $data['delivered_at'] = '';
        $data['merchant_notes'] = $track ? $track->getTitle() : '';
        $data['status'] = $this->_getShipmentStatus($shipment);
        $data['merchant_shipment_id'] = $shipment->getIncrementId();
        $data['shipment_items'] = $this->_getShipmentItems($shipment);
        
        $response = $this->_shipment($token, json_encode($data));
        if (!isset($response->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Shipment error.'));
        } 
    }

    protected function _getInvoiceId($order) {
        $invoices = $order->getInvoiceCollection();
        if ($invoices->getSize() < 1) {
            throw new \Magento\Framework\Validator\Exception(__('Please Invoice Order Before Shipping.'));
        }
        
        $invoice = $invoices->getFirstItem();
        return $invoice->getTransactionId(); 
    }
    
    protected function _getShipmentItems($shipment) {
        $data = array();
        
        $items = $shipment->getAllItems();
        foreach ($items as $item) {
            $product = $item->getOrderItem()->getProduct();
            $shipmentItem = array();
            
            $shipmentItem['price_ea_cents'] = $item->getPrice() * 100;
            $shipmentItem['quantity'] = $item->getQty();
            $shipmentItem['price_total_cents'] = $item->getPrice() * $item->getQty() * 100;
            $shipmentItem['currency'] = $shipment->getOrder()->getData('order_currency_code');;
            $shipmentItem['title'] = $product->getName();
            $shipmentItem['merchant_notes'] = '';
            $shipmentItem['description'] = $product->getMetaDescription();
            $shipmentItem['sku'] = $item->getSku();
            $shipmentItem['variant_info'] = '';
            $shipmentItem['vendor'] = '';
            $shipmentItem['view_product_url'] = $product->getProductUrl();

            $data[] = $shipmentItem;
        }
        
        return $data;
    }
    
    protected function _getShipmentStatus($shipment) {
        $order = $shipment->getOrder();

        $qtyOrdered = $order->getTotalQtyOrdered();
        $qtyShipped = $shipment->getTotalQty();
        
        if ($qtyOrdered > $qtyShipped) {
            return self::SHIPPING_PARTIAL;
        }
        
        return self::SHIPPING_COMPLETE;
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