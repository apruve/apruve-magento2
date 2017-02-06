<?php
namespace Apruve\Payment\Model\Observer;

use Magento\Framework\Event\ObserverInterface;

class Observer implements ObserverInterface
{   
    const CODE = 'apruve';

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
         
        foreach ($order->getInvoiceCollection() as $invoice) {
        }
        
        /**Create Shipment*/
        $token = $invoice->getTransactionId();
        $tracks = $shipment->getAllTracks();
        $track = end($tracks);

        $data = array();
        $data['amount_cents'] = $order->getSubtotal() * 100;
        $data['shipper'] = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();
        $data['tracking_number'] = $track ? $track->getTrackNumber() : '';
        $data['shipped_at'] = date(DATE_ISO8601, strtotime($shipment->getCreatedAt()));
        $data['delivered_at'] = '';
        $data['merchant_notes'] = $track ? $track->getTitle() : '';
        $data['currency'] = $order->getData('base_currency_code');
        $data['invoice_items'] = $this->_getInvoiceItems($token);
        $response = $this->_shipment($token, json_encode($data));
        if (!isset($response->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Shipment error.'));
        } 
    }

    protected function _getInvoiceItems($token) {
        $apiKey = $this->_method->getConfigData('api_key');
        $mode = $this->_method->getConfigData('mode');
        $url = sprintf("https://%s.apruve.com/api/v4/invoices/%s", $mode, $token);
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "accept: application/json",
                "apruve-api-key: " . $apiKey 
            ),
        ));
        
        $response = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpStatus != 404) {
            $response = json_decode($response, 1);
        }
        
        if (isset($response['invoice_items'])) {
            $data = array();
            foreach ($response['invoice_items'] as $item) {
                $data[] = array("id" => $item['id']);
            }
            
            return $data;
        }
        
        throw new \Magento\Framework\Validator\Exception(__('No items to invoice.'));
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