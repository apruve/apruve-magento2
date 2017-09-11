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
    protected $_canRefund = false;
    protected $_canRefundInvoicePartial = false;
    protected $_isInitializeNeeded = false;
    protected $_canUseInternal = true;
    protected $_configProvider;
    protected $_corporateAccount;

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (! $quote || ! $this->getConfigData('active')) {
            return false;
        }

        return true;
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        $additionalData = $data->getAdditionalData();
        if (!isset($additionalData['apruve_order_id'])) {
            throw new \Magento\Framework\Validator\Exception(__('Payment Error.'));
        }
        $this->getInfoInstance()->setAdditionalInformation(['aid' => $additionalData['apruve_order_id']]);
        return $this;
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        parent::capture($payment, $amount);
    }

    public function validate()
    {
        $info = $this->getInfoInstance();
        $token = $info->getAdditionalInformation()['aid'];
        if (!isset($token)) {
            $errorMsg = __('Empty Payment Order ID');
            throw new \Magento\Framework\Validator\Exception($errorMsg);
        }
        return true;
    }

    protected function _isAdmin($store = null)
    {
        /** @var \Magento\Framework\ObjectManagerInterface $om */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Magento\Framework\App\State $state */
        $state = $objectManager->get('Magento\Framework\App\State');

        return 'adminhtml' === $state->getAreaCode();
    }

    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();

        # Authorize the order
        $corporateAccount = $this->_getCorporateAccount($order->getCustomerEmail());
        $buyer            = $this->_getBuyer($corporateAccount, $order->getCustomerEmail());
        if (empty($buyer->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Cannot find Apruve corporate account for that customer'));
        }

        /**Validate*/
        if ($amount <= 0) {
            throw new \Magento\Framework\Validator\Exception(__('Invalid amount for capture.'));
        }

        $data                        = [];
        $data['merchant_id']         = $this->_getMerchantKey();
        $data['merchant_order_id']   = $order->getIncrementId();
        $data['shopper_id']          = $buyer->id;
        $data['amount_cents']        = ( $order->getPayment()->getData('amount_ordered') ) * 100;
        $data['currency']            = $order->getData('base_currency_code');
        $data['shipping_cents']      = $order->getData('shipping_amount') * 100;
        $data['tax_cents']           = $order->getData('tax_amount') * 100;
        $data['merchant_notes']      = '';
        $data['merchant_invoice_id'] = 'blah!';
        $data['due_at']              = '';
        $data['order_items']         = [];
        $data['final_on_create']     = 'false';
        $data['invoice_on_create']   = 'false';
        $data['payment_term']        = [ 'corporate_account_id' => $corporateAccount->id ];


        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $product       = $objectManager->get('Magento\Catalog\Model\Product');
        $helper        = $objectManager->get('\Apruve\Payment\Helper\Data');

        foreach ($order->getAllVisibleItems() as $item) {
            if ($item->getParentItem()) {
                continue;
            }

            $product->clearInstance();
            $product->load($item->getProductId());

            $lineItem = [];

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
            $discountItem                      = [];
            $discountItem['price_ea_cents']    = (int) ( $discount * 100 );
            $discountItem['quantity']          = 1;
            $discountItem['price_total_cents'] = (int) ( $discount * 100 );
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
        if (! isset($response->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Apruve order creation error. '));
        }

        $token = $response->id;

        $payment->setParentTransactionId(null);
        $payment->setAmount($amount)->setTransactionId($token)->setIncrementalId($token);
        $payment->setIncrementId($token)->setIsTransactionClosed(0);
        $order->save();

        return $this;
    }

    protected function _getCorporateAccount($email)
    {
        $result   = $this->_apruve(
            'corporate_accounts?email=' . urlencode($email),
            $this->_getMerchantKey(),
            '',
            'merchants',
            'GET'
        );
        $response = array_pop($result);

        if (empty($response)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('An unknown error has occurred.  Please try again or contact Apruve support.' . __(var_dump($result))));
        }

        return $response;
    }

    /** Apruve API Manipulation method */
    protected function _apruve($action, $token, $data = '', $object = 'orders', $requestType = 'POST')
    {
        $url = sprintf("https://%s.apruve.com/api/v4/%s", $this->getConfigData('mode'), $object);

        if (! empty($token)) {
            // Here we need to remove "-void", etc.
            $token = preg_replace('/\-.*/', '', $token);
            $url   = sprintf($url . '/%s', $token);
        }

        if (! empty($action)) {
            $url = sprintf($url . "/%s", $action);
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => $requestType,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'apruve-api-key: ' . $this->getConfigData('api_key'),
                'content-type: application/json'
            ]
        ]);

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

        throw new \Magento\Framework\Exception\LocalizedException(__('Could not complete operation with ' . $object));
    }

    protected function _getMerchantKey()
    {
        $id = $this->getConfigData('merchant_id');

        return $id ? $id : null;
    }

    protected function _getBuyer($corporateAccount, $email)
    {
        foreach ($corporateAccount->authorized_buyers as $account) {
            if ($account->email == $email) {
                return $account;
            }
        }
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        $data = $payment->getIncrementId();
        if ($data) {
            try {
                $response = $this->_apruve(self::CANCEL_ACTION, $data);
            } catch (\Exception $e) {
            }
        }

        return parent::cancel($payment);
    }

    public function getConfig()
    {
        $objectManager         = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_configProvider = $objectManager->create('Apruve\Payment\Model\CustomConfigProvider');

        return $this->_configProvider;
    }

    public function getCode()
    {
        return 'apruve';
    }

    protected function _updateMerchantID($token, $orderId)
    {
        $action                    = '';
        $data                      = [];
        $data['merchant_order_id'] = $orderId;
        $object                    = 'orders';
        $requestType               = 'PUT';

        $response = $this->_apruve($action, $token, json_encode($data), $object, $requestType);

        return $response;
    }

    protected function _getLineItems($order)
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'title'             => $item->getName(),
                'description'       => $item->getDescription(),
                'price_total_cents' => $item->getRowTotal() * 100,
                'price_ea_cents'    => $item->getPrice() * 100,
                'quantity'          => $item->getQtyOrdered(),
                'sku'               => $item->getSku(),
                'view_product_url'  => $item->getProduct()->getUrlInStore()
            ];
        }

        return $items;
    }
}
