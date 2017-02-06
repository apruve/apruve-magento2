<?php

namespace Apruve\Payment\Model;

class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'apruve';

    const AUTHORIZE_ACTION = 'finalize';
    const CAPTURE_ACTION = 'invoices';
    const CANCEL_ACTION = 'cancel';

    protected $_code                = self::CODE;
    protected $_isGateway           = true;
    protected $_canAuthorize        = true;
    protected $_canCapture          = true;
    protected $_canCapturePartial   = false;
    protected $_canVoid             = true;
    protected $_isInitializeNeeded  = false;
    protected $_isOffline           = false;
    protected $_canUseInternal      = false;
    protected $_configProvider;
    
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null) {
        if (!$quote || !$this->getConfigData('active')) {
            return false;
        }
        return true;
    }

    public function assignData(\Magento\Framework\DataObject $data) {
        $additionalData = $data->getAdditionalData();
        if (empty($additionalData['apruve_order_id'])) {
            throw new \Magento\Framework\Validator\Exception(__('Payment Error.'));
        }
        $this->getInfoInstance()->setAdditionalInformation(array('aid' => $additionalData['apruve_order_id']));

        return $this;
    }

    public function validate() {
        $info = $this->getInfoInstance();
        $token = $info->getAdditionalInformation()['aid'];

        if (empty($token)){
            $errorMsg = __('Empty Payment Order ID');
            throw new \Magento\Framework\Validator\Exception($errorMsg);
        }

        return true;
    }

    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        $info = $this->getInfoInstance();
        $token = $info->getAdditionalInformation()['aid'];
        
        $response = $this->_apruve(self::AUTHORIZE_ACTION, $token);
        if (!isset($response->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Payment authorize error.'));
        }
        
        $payment->setTransactionId($token)->setIsTransactionClosed(0);
    }
    
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        $order = $payment->getOrder();
        $data = $payment->getAdditionalInformation();
        $token = $data['aid'];

        /**Validate*/
        if ($amount <= 0) {
            throw new \Magento\Framework\Validator\Exception(__('Invalid amount for capture.'));
        }

        /**Create Invoice*/
        $invoices = $order->getInvoiceCollection();
        foreach ($invoices as $invoice) {
            $invoiceId = $invoice->getId();
        }
        
        $data = array();
        $data['amount_cents'] = ($order->getPayment()->getData('amount_ordered')) * 100;
        $data['currency'] = $order->getData('base_currency_code');
        $data['shipping_cents'] = $order->getData('shipping_amount') * 100;
        $data['tax_cents'] = $order->getData('tax_amount') * 100;
        $data['merchant_notes'] = '';
        $data['merchant_invoice_id'] = $invoiceId;
        $data['due_at'] = '';
        $data['invoice_items'] = array();
        $data['issue_on_create'] = 'true';

        $invoiceItem = array();
        foreach ($invoice->getAllItems() as $item) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $product = $objectManager->get('Magento\Catalog\Model\Product')->load($item->getProductId());
            
            $invoiceItem['price_ea_cents'] = $item->getData('price') * 100;
            $invoiceItem['quantity'] = $item->getData('qty');
            $invoiceItem['price_total_cents'] = $invoiceItem['price_ea_cents'] * $invoiceItem['quantity'];
            $invoiceItem['currency'] = $invoice->getData('order_currency_code');
            $invoiceItem['title'] = $item->getData('name');
            $invoiceItem['merchant_notes'] = '';
            $invoiceItem['description'] = $item->getData('name');
            $invoiceItem['sku'] = $item->getData('sku');
            $invoiceItem['variant_info'] = '';
            $invoiceItem['vendor'] = '';
            $invoiceItem['view_product_url'] = $product->getProductUrl();

            $data['invoice_items'][] = $invoiceItem;
        }

        $response = $this->_apruve(self::CAPTURE_ACTION, $token, json_encode($data));
        if (!isset($response->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Payment capture error.'));
        }
        
        $payment
            ->setAmount($amount)
            ->setTransactionId($response->id);

        return $this;
    }
   
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        $data = $payment->getAdditionalInformation();
        $token = $data['aid'];
        $response = $this->_apruve(self::CANCEL_ACTION, $token);

        if (!$response) {
            throw new \Magento\Framework\Validator\Exception(__('Order cancel error.'));
        }

        return parent::cancel($payment);
    }

    protected function _apruve($action, $token, $data = '') {
        $url = sprintf("https://%s.apruve.com/api/v4/orders/%s/%s", $this->getConfigData('mode'), $token, $action);
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
                'apruve-api-key: ' . $this->getConfigData('api_key'),
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
    
    public function getConfig() {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_configProvider = $objectManager->create('Apruve\Payment\Model\CustomConfigProvider');
        
        return $this->_configProvider;
    }
}