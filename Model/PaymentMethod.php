<?php

namespace Apruve\Payment\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;

class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'apruve';
    const DISCOUNT = 'Discount';

    const AUTHORIZE_ACTION = 'finalize';
    const OFFLINE_ACTION = '';
    const CAPTURE_ACTION = 'invoices';
    const CANCEL_ACTION = 'cancel';

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
    protected $_token = null;
    protected $_order = null;
    protected $_order_data = null;
    protected $_logger;

    // Make our own constructor so that we can inject a logger that we understand, rather than the weird magento wrapper one
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $parentLogger, // We want a different type of logger that we understand
        \Psr\Log\LoggerInterface $logger, // This is our logger
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = [],
        DirectoryHelper $directory = null)
    {
        $this->_logger = $logger;
        parent::__construct($context, $registry, $extensionFactory, $customAttributeFactory, $paymentData, $scopeConfig, $parentLogger, $resource, $resourceCollection, $data, $directory);

    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$quote || !$this->getConfigData('active')) {
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

    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->debug("PaymentMethod::authorize called");
        $info = $this->getInfoInstance();
        $token = $info->getAdditionalInformation()['aid'];

        if (!empty($token)) {
            $this->_logger->debug("Authorizing an online order");
            // It's an online order, so it has already been created on the apruve side by the checkout js. We just need to finalize it.
            $response = $this->_apruve(self::AUTHORIZE_ACTION, $token);
            if (!isset($response->id)) {
                throw new \Magento\Framework\Validator\Exception(__('Payment authorize error.'));
            }

            $payment->setLastTransId($token)->setTransactionId($token)->setIsTransactionClosed(0);

            // If Reserved Order ID is not correct
            $order = $payment->getOrder();
            if($response->merchant_order_id != $order->getIncrementId()); {
                $this->_updateMerchantID($token, $order->getIncrementId());
            }
        } else {
            // It's an offline order, so apruve has never heard of it.
            $this->_logger->debug("Authorizing an offline order");
            // Next two methods build the json we're going to send to apruve and put it in $this->_order_data
            $this->generate_order_data($payment, $amount);
            $this->generate_offline_order_data($payment, $amount);
            $this->_logger->debug("Built an offline order, about to submit it");
            $response = $this->_apruve(self::OFFLINE_ACTION, $this->_token, json_encode($this->_order_data));
            if (!isset($response->id)) {
                throw new \Magento\Framework\Validator\Exception(__('Offline order creation error.'));
            }
            $payment->setLastTransId($response->id)->setTransactionId($response->id)->setIsTransactionClosed(false);
        }
        return $this;
    }

    public function validate()
    {
        $this->_logger->debug("PaymentMethod::validate called");
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
        $this->_logger->debug("PaymentMethod::_isAdmin called");
        /** @var \Magento\Framework\ObjectManagerInterface $om */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Magento\Framework\App\State $state */
        $state = $objectManager->get('Magento\Framework\App\State');

        return 'adminhtml' === $state->getAreaCode();
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->debug("PaymentMethod::capture called, doing nothing");
        // We used to make an invoice here, but I think that's wrong. We should be doing that in the shipment observer instead
//        $this->generate_order_data($payment, $amount, false);
//
//        $response = $this->_apruve(self::CAPTURE_ACTION, $payment->getParentTransactionId(), json_encode($this->_order_data));
//        if (!isset($response->id)) {
//            throw new \Magento\Framework\Validator\Exception(__('Invoice creation error.'));
//        }
//
//        $payment
//            ->setAmount($amount)
//            ->setTransactionId($response->id);
        return $this;
    }

    protected function generate_offline_order_data(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $corporateAccount = $this->_getCorporateAccount($this->_order->getCustomerEmail());
        $buyer = $this->_getBuyer($corporateAccount, $this->_order->getCustomerEmail());
        if (empty($buyer->id)) {
            throw new \Magento\Framework\Validator\Exception(__('Cannot find Apruve corporate account for that customer'));
        }

        $this->_order_data['shopper_id'] = $buyer->id;
        $this->_order_data['payment_term'] = ['corporate_account_id' => $corporateAccount->id];
        $this->_order_data['finalize_on_create'] = 'true';
    }

    protected function generate_order_data(\Magento\Payment\Model\InfoInterface $payment, $amount, $newOrder = true)
    {
        // Fills in the _order_data associative array with data corresponding to either an apruve order or an apurve invoice
        // If $newOrder is true then makes an order, if $newOrder is false then makes an invoice
        // TODO: split the two use cases
        /**Validate*/
        if ($amount <= 0) {
            throw new \Magento\Framework\Validator\Exception(__('Invalid amount for capture.'));
        }

        $this->_order = $payment->getOrder();

        $this->_order_data = [];

        $this->_order_data['merchant_id'] = $this->_getMerchantKey();
        $this->_order_data['merchant_order_id'] = $this->_order->getIncrementId();

        $this->_order_data['amount_cents'] = ($this->_order->getPayment()->getData('amount_ordered')) * 100;
        $this->_order_data['currency'] = $this->_order->getData('base_currency_code');
        $this->_order_data['shipping_cents'] = $this->_order->getData('shipping_amount') * 100;
        $this->_order_data['tax_cents'] = $this->_order->getData('tax_amount') * 100;
        $this->_order_data['merchant_notes'] = '';
        if($newOrder)
        {
            $this->_order_data['order_items'] = [];
            $this->_order_data['invoice_on_create'] = 'false';
        } else {
            $this->_order_data['invoice_items'] = [];
        }


        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $product = $objectManager->get('Magento\Catalog\Model\Product');
        $helper = $objectManager->get('\Apruve\Payment\Helper\Data');

        foreach ($this->_order->getAllVisibleItems() as $item) {
            if ($item->getParentItem()) {
                continue;
            }

            $product->clearInstance();
            $product->load($item->getProductId());

            $lineItem = [];

            $lineItem['price_ea_cents'] = $item->getData('price') * 100;
            $lineItem['quantity'] = $item->getQtyOrdered();
            $lineItem['price_total_cents'] = $lineItem['price_ea_cents'] * $lineItem['quantity'];
            $lineItem['currency'] = $this->_order->getData('order_currency_code');
            $lineItem['title'] = $item->getData('name');
            $lineItem['merchant_notes'] = '';
            $lineItem['description'] = $item->getData('name');
            $lineItem['sku'] = $item->getData('sku');
            $lineItem['variant_info'] = '';
            $lineItem['vendor'] = '';
            $lineItem['view_product_url'] = $product->getProductUrl();

            if($newOrder)
            {
                $this->_order_data['order_items'][] = $lineItem;
            } else
            {
                $this->_order_data['invoice_items'][] = $lineItem;
            }

        }

        /**Add Discount Item*/
        $discount = $this->_order->getDiscountAmount();
        if ($discount < 0) {
            $discountItem = [];
            $discountItem['price_ea_cents'] = (int)($discount * 100);
            $discountItem['quantity'] = 1;
            $discountItem['price_total_cents'] = (int)($discount * 100);
            $discountItem['currency'] = $this->_order->getData('order_currency_code');
            $discountItem['title'] = self::DISCOUNT;
            $discountItem['merchant_notes'] = '';
            $discountItem['description'] = self::DISCOUNT;
            $discountItem['sku'] = self::DISCOUNT;
            $discountItem['variant_info'] = '';
            $discountItem['vendor'] = '';
            $discountItem['view_product_url'] = $helper->getStoreUrl();

            if($newOrder)
            {
                $this->_order_data['order_items'][] = $discountItem;
            } else
            {
                $this->_order_data['invoice_items'][] = $discountItem;
            }
        }
    }

    protected function _getCorporateAccount($email)
    {
        $result = $this->_apruve(
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

        if (!empty($token)) {
            // Here we need to remove "-void", etc.
            $token = preg_replace('/\-.*/', '', $token);
            $url = sprintf($url . '/%s', $token);
        }

        if (!empty($action)) {
            $url = sprintf($url . "/%s", $action);
        }

        $this->_logger->debug("PaymentMethod::_apruve called. Sending $requestType to $url");
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $requestType,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'apruve-api-key: ' . $this->getConfigData('api_key'),
                'content-type: application/json'
            ]
        ]);

        $response = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new \Magento\Framework\Exception\LocalizedException(__($error));
        }

        if ($httpStatus != 404) {
            $this->_logger->debug("Got a good response with status code $httpStatus");
            return json_decode($response);
        }
        $this->_logger->debug("Got a bad response with status code $httpStatus, throwing exception");
        throw new \Magento\Framework\Exception\LocalizedException('Could not complete operation with object ' . json_decode($response));
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
        $token = $payment->getParentTransactionId();
        if ($token) {
            try {
                $response = $this->_apruve(self::CANCEL_ACTION, $token);
            } catch (\Exception $e) {
            }
        }

        return parent::cancel($payment);
    }

    public function getConfig()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_configProvider = $objectManager->create('Apruve\Payment\Model\CustomConfigProvider');

        return $this->_configProvider;
    }

    public function getCode()
    {
        return 'apruve';
    }

    protected function _updateMerchantID($token, $orderId)
    {
        $action = '';
        $data = [];
        $data['merchant_order_id'] = $orderId;
        $object = 'orders';
        $requestType = 'PUT';

        $response = $this->_apruve($action, $token, json_encode($data), $object, $requestType);

        return $response;
    }

    protected function _getLineItems($order)
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'title' => $item->getName(),
                'description' => $item->getDescription(),
                'price_total_cents' => $item->getRowTotal() * 100,
                'price_ea_cents' => $item->getPrice() * 100,
                'quantity' => $item->getQtyOrdered(),
                'sku' => $item->getSku(),
                'view_product_url' => $item->getProduct()->getUrlInStore()
            ];
        }

        return $items;
    }
}