<?php

namespace Apruve\Payment\Model;

class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'apruve';
    const DISCOUNT = 'Discount';

    const AUTHORIZE_ACTION = 'finalize';
    const CAPTURE_ACTION = 'invoices';
    const CANCEL_ACTION = 'cancel';

    protected $_code                = self::CODE;
    protected $_isGateway           = true;
    protected $_canAuthorize        = true;
    protected $_canCapture          = true;
    protected $_canCapturePartial   = false;
    protected $_canVoid             = true;
    protected $_canRefund           = true;
    protected $_canRefundInvoicePartial = true;
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
        
        // If Reserved Order ID is not correct
        $order = $payment->getOrder();
        if($response->merchant_order_id != $order->getIncrementId()); {
            $this->_updateMerchantID($token, $order->getIncrementId());
        }
        
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
        $data['invoice_items'] = [];
        $data['issue_on_create'] = 'true';
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $product = $objectManager->get('Magento\Catalog\Model\Product');
        $helper = $objectManager->get('\Apruve\Payment\Helper\Data');
            
        foreach ($invoice->getItems() as $item) {
            if ($item->getOrderItem()->getParentItem()) {
                continue;
            }
            
            $product->clearInstance();
            $product->load($item->getProductId());

            $invoiceItem = array();
            
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

        /**Add Discount Item*/
        $discount = $order->getDiscountAmount();
        if ($discount < 0) {
            $discountItem = [];
            $discountItem['price_ea_cents'] = (int)$discount * 100;
            $discountItem['quantity'] = 1;
            $discountItem['price_total_cents'] = (int)$discount * 100;
            $discountItem['currency'] = $invoice->getData('order_currency_code');
            $discountItem['title'] = self::DISCOUNT;
            $discountItem['merchant_notes'] = '';
            $discountItem['description'] = self::DISCOUNT;
            $discountItem['sku'] = self::DISCOUNT;
            $discountItem['variant_info'] = '';
            $discountItem['vendor'] = '';
            $discountItem['view_product_url'] = $helper->getStoreUrl();
            
            $data['invoice_items'][] = $discountItem;
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
   
    public function cancel(\Magento\Payment\Model\InfoInterface $payment) {
        $data = $payment->getAdditionalInformation();
        if ($data && $data['aid']) {
            $token = $data['aid'];
            try {
                $response = $this->_apruve(self::CANCEL_ACTION, $token);
            } catch (\Exception $e) {
            }
        }

        return parent::cancel($payment);
    }
    
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        if (!$this->canRefund()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('The refund action is not available.'));
        }
    
        $invoice = $payment->getCreditmemo()->getInvoice();
        $invoiceId = $invoice->getTransactionId();
        if (!$invoiceId) {
            throw new \Magento\Framework\Exception\LocalizedException(__('The refund action is not available.'));
        }
        
        $refundAmount = $invoice->getGrandTotal() - $amount;
        $data = array();
        $data['amount_cents'] = $refundAmount * 100;
        
        $this->_apruve('', $invoiceId, json_encode($data), 'invoices', 'PUT');
        return $this;
    }

    /** Apruve API Manipulation method */
    protected function _apruve(
            $action,
            $token,
            $data = '',
            $object = 'orders',
            $requestType = 'POST'
    ){
        $url = sprintf("https://%s.apruve.com/api/v4/%s/%s/%s", $this->getConfigData('mode'), $object, $token, $action);
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $requestType,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => array(
                'accept: application/json',
                'apruve-api-key: ' . $this->getConfigData('api_key'),
                'content-type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            throw new \Magento\Framework\Exception\LocalizedException(__($error));
        }
        
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
    
    protected function _updateMerchantID($token, $orderId){
        $action = '';
        $data = [];
        $data['merchant_order_id'] = $orderId;
        $object = 'orders';
        $requestType = 'PUT';
                            
        $response = $this->_apruve($action, $token, json_encode($data), $object, $requestType);
        return $response;
    }
}