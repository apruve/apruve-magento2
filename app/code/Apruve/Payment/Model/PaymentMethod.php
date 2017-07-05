<?php

namespace Apruve\Payment\Model;

class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'apruve';
    const DISCOUNT = 'Discount';

    const CANCEL_ACTION = 'cancel';
    const FINALIZE_ACTION = 'finalize';

    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canVoid = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_isInitializeNeeded = false;
    protected $_canUseInternal = true;
    protected $_configProvider;

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$quote || !$this->getConfigData('active')) {
            return false;
        }
        return true;
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        // $additionalData = $data->getAdditionalData();
        // if (empty($additionalData['apruve_order_id'])) {
        //     throw new \Magento\Framework\Validator\Exception(__('Apruve assignData Error.'. print_r($additionalData, true)));
        // } else {
        // $this->getInfoInstance()->setAdditionalInformation(array('aid' => $additionalData['apruve_order_id']));
        // }

        return $this;
    }

    public function validate()
    {
        if (!$this->_isAdmin()) {
            throw new \Magento\Framework\Validator\Exception(__('You are not authorized to make an Apruve transaction.'));
        }
        return $this;
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {

        $info  = $this->getInfoInstance();
        $order = $payment->getOrder();
        // $data = $payment->getAdditionalInformation();

        //  $token = $info->getAdditionalInformation()['aid'];

        # Authorize the order
        $corporateAccount = $this->_getCorporateAccount('corporateuser@example.com');
        $buyer = $this->_getBuyer($corporateAccount, 'corporateuser@example.com');
        if (empty($buyer->id)){
            throw new \Magento\Framework\Validator\Exception(__('Cannot find Apruve corporate account for that customer'));
        }

        /**Validate*/
        if ($amount <= 0) {
            throw new \Magento\Framework\Validator\Exception(__('Invalid amount for capture.'));
        }

        $data                        = array();
        $data['merchant_id']         = $this->_getMerchantKey();
        $data['shopper_id']          = $buyer->id;
        $data['amount_cents']        = ($order->getPayment()->getData('amount_ordered')) * 100;
        $data['currency']            = $order->getData('base_currency_code');
        $data['shipping_cents']      = $order->getData('shipping_amount') * 100;
        $data['tax_cents']           = $order->getData('tax_amount') * 100;
        $data['merchant_notes']      = '';
        // $data['merchant_invoice_id'] = $invoiceId;
        $data['due_at']              = '';
        $data['order_items']       = array();
        $data['issue_on_create']     = 'true';
        $data['payment_term']        = array('corporate_account_id' => $corporateAccount->id);


        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $product       = $objectManager->get('Magento\Catalog\Model\Product');
        $helper        = $objectManager->get('\Apruve\Payment\Helper\Data');

        foreach ($order->getAllVisibleItems() as $item) {
            if ($item->getParentItem()) {
                continue;
            }

            $product->clearInstance();
            $product->load($item->getProductId());

            $lineItem = array();

            $lineItem['price_ea_cents']    = $item->getData('price') * 100;
            $lineItem['quantity']          = $item->getQtyOrdered();
            $lineItem['price_total_cents'] = $lineItem['price_ea_cents'] * $lineItem['quantity'];
            $lineItem['currency']          = $order->getData('order_currency_code');
            $lineItem['title']             = $item->getData('name');
            $lineItem['merchant_notes']    = '';
            $lineItem['description']       = $item->getData('name');
            $lineItem['sku']               = $item->getData('sku');
            $lineItem['variant_info']      = '';
            $lineItem['vendor']            = '';
            $lineItem['view_product_url']  = $product->getProductUrl();

            $data['order_items'][] = $lineItem;
        }

        /**Add Discount Item*/
        $discount = $order->getDiscountAmount();
        if ($discount < 0) {
            $discountItem                      = array();
            $discountItem['price_ea_cents']    = (int) ($discount * 100);
            $discountItem['quantity']          = 1;
            $discountItem['price_total_cents'] = (int) ($discount * 100);
            $discountItem['currency']          = $order->getData('order_currency_code');
            $discountItem['title']             = self::DISCOUNT;
            $discountItem['merchant_notes']    = '';
            $discountItem['description']       = self::DISCOUNT;
            $discountItem['sku']               = self::DISCOUNT;
            $discountItem['variant_info']      = '';
            $discountItem['vendor']            = '';
            $discountItem['view_product_url']  = $helper->getStoreUrl();

            $data['order_items'][] = $discountItem;
        }

        $response = $this->_apruve('', '', json_encode($data));
        if (!isset($response->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Apruve order creation error.: ' . var_dump($response)));
        }

        $token = $response->id;

        $payment->setAmount($amount)->setTransactionId($response->id);
        $payment->setTransactionId($token)->setIsTransactionClosed(0);

        return $this;
    }
    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        $data = $payment->getTransactionId();
        if ($data) {
            try {
                $response = $this->_apruve(self::CANCEL_ACTION, $data);
            }
            catch (\Exception $e) {
            }
        }

        return parent::cancel($payment);
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if (!$this->canRefund()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('The refund action is not available.'));
        }

        $invoice   = $payment->getCreditmemo()->getInvoice();
        $invoiceId = $invoice->getTransactionId();
        if (!$invoiceId) {
            throw new \Magento\Framework\Exception\LocalizedException(__('The refund action is not available.'));
        }

        $refundAmount         = $invoice->getGrandTotal() - $amount;
        $data                 = array();
        $data['amount_cents'] = $refundAmount * 100;

        $this->_apruve('', $invoiceId, json_encode($data), 'invoices', 'PUT');
        return $this;
    }

    /** Apruve API Manipulation method */
    protected function _apruve($action, $token, $data = '', $object = 'orders', $requestType = 'POST')
    {
        $url = sprintf("https://%s.apruve.com/api/v4/%s", $this->getConfigData('mode'), $object);

        if(!empty($token)){
          $url = sprintf($url . "/%s", $token);
        }

        if(!empty($action)){
          $url = sprintf($url . "/%s", $action);
        }

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
            )
        ));

        $response   = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error      = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new \Magento\Framework\Exception\LocalizedException(__($error));
        }

        if ($httpStatus != 404) {
            return json_decode($response);
        }
        return false;
    }

    public function getConfig()
    {
        $objectManager         = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_configProvider = $objectManager->create('Apruve\Payment\Model\CustomConfigProvider');

        return $this->_configProvider;
    }

    protected function _getCorporateAccount($email)
    {
        $result   = $this->_apruve('corporate_accounts?email=' . urlencode($email), $this->_getMerchantKey(), '', 'merchants', 'GET');
        $response = array_pop($result);

        if (empty($response)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('An unknown error has occurred.  Please try again or contact Apruve support.' . __(var_dump($result))));
        }
        return $response;
    }

    protected function _getBuyer($corporateAccount, $email)
    {
        foreach($corporateAccount->authorized_buyers as $account){
            if($account->email == $email){
                return $account;
            }
        }
    }

    protected function _updateMerchantID($token, $orderId)
    {
        $action                    = '';
        $data                      = array();
        $data['merchant_order_id'] = $orderId;
        $object                    = 'orders';
        $requestType               = 'PUT';

        $response = $this->_apruve($action, $token, json_encode($data), $object, $requestType);
        return $response;
    }

    protected function _isAdmin($store = null)
    {
        /** @var \Magento\Framework\ObjectManagerInterface $om */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Magento\Framework\App\State $state */
        $state         = $objectManager->get('Magento\Framework\App\State');
        return 'adminhtml' === $state->getAreaCode();
    }

    protected function _getMerchantKey()
    {
        $id = $this->getConfigData('merchant_id');
        return $id ? $id : null;
    }

    protected function _getLineItems($order)
    {
        $items = array();
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = array(
                'title' => $item->getName(),
                'description' => $item->getDescription(),
                'price_total_cents' => $item->getRowTotal() * 100,
                'price_ea_cents' => $item->getPrice() * 100,
                'quantity' => $item->getQtyOrdered(),
                'sku' => $item->getSku(),
                'view_product_url' => $item->getProduct()->getUrlInStore()
            );
        }
        return $items;
    }
}
