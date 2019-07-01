<?php

namespace Apruve\Payment\Observer;

class InvoiceObserver implements \Magento\Framework\Event\ObserverInterface
{
    const CODE = 'apruve';
    const DISCOUNT = 'Discount';
    const CURRENCY = 'USD';
    const SHIPPING_PARTIAL = 'partial';
    const SHIPPING_COMPLETE = 'fulfilled';

    protected $method;

    public function __construct(
        \Magento\Payment\Helper\Data $paymentHelper,
        \Apruve\Payment\Helper\Data $helper,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Sales\Api\Data\InvoiceInterface $invoiceInterface,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->method            = $paymentHelper->getMethodInstance(self::CODE);
        $this->_helper           = $helper;
        $this->_invoiceService   = $invoiceService;
        $this->_transaction      = $transaction;
        $this->_order            = $order;
        $this->_invoiceInterface = $invoiceInterface;
        $this->_logger = $logger;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->_logger->debug('Executing invoice observer');

        $invoice = $observer->getEvent()->getInvoice();

        $this->_logger->debug(get_class($invoice));

        if ($invoice->getUpdatedAt() == $invoice->getCreatedAt()) {
            $this->_logger->debug('Logic for new invoices');

            // Logic for new invoices
            $this->_order = $invoice->getOrder();
            $payment = $this->_order->getPayment();

            $this->_logger->debug('Only process Apruve invoices');

            // Only process Apruve invoices
            if ($payment->getMethod() == 'apruve' && empty($invoice->getLastTransId())) {
                $this->_createApruveInvoiceFromMagentoInvoice($invoice);
            };
        }
    }

    protected function _createApruveInvoiceFromMagentoInvoice($invoice)
    {
        $token = $this->_order->getPayment()->getLastTransId();

        $this->_logger->debug('Apruve __createApruveInvoiceFromMagentoInvoice');
        try {
            $data = $this->_getInvoiceData($invoice);

            // Send the invoice to apruve.
            $response = $this->_apruve('invoices', $token, $data);
            if (!isset($response->id)) {
                throw new \Magento\Framework\Validator\Exception(__('Apruve invoice creation error.'));
            }
            $invoice->setTransactionId($response->id); // Apruve invoice id gets set on the invoice, and vice versa

            $transactionSave = $this->_transaction->addObject(
                $invoice
            )->addObject(
                $invoice->getOrder()
            );
            $transactionSave->save();

            return $response->id;

        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__('Apruve invoice creation.' . $e->getMessage()));
        }

        throw new \Magento\Framework\Validator\Exception(__('Apruve invoice creation.'));
    }

    protected function _getInvoiceData($invoice, $itemQty = null)
    {

        $this->_logger->debug('Apruve _getInvoiceData');

        $invoiceItems = $invoice->getAllItems();

        $items = [];
        foreach ($invoiceItems as $invoiceItem) {
            $orderItem = $invoiceItem->getOrderItem();
            /* create invoice item for apruve */
            $item = [];
            $item['price_ea_cents'] = $this->convertPrice($invoiceItem->getBasePrice());
            $item['quantity'] = intval($invoiceItem->getQty());
            $item['price_total_cents'] = $this->convertPrice($invoiceItem->getBaseRowTotal());
            $item['currency'] = $this->getCurrency();
            $item['title'] = $invoiceItem->getName();
            $item['merchant_notes'] = $invoiceItem->getAdditionalData();
            $item['description'] = $invoiceItem->getDescription();
            $item['sku'] = $invoiceItem->getSku();
            $item['variant_info'] = $invoiceItem->getProductOptions();
            $item['vendor'] = '';
            /* add invoice item to $items array */
            $items[] = $item;
        }
        // get discount line item
        if (($discountItem = $this->_getDiscountItem($invoice))) {
            $items[] = $discountItem;
        }

        $this->_logger->debug(print_r($items));
        $this->_logger->debug('Apruve _getInvoiceDatav2');

        $magento_invoice_id = $invoice->getIncrementId();
        $this->_logger->debug("Preparing to create an apruve invoice from magento invoice $magento_invoice_id");

        if (!isset($magento_invoice_id) || $magento_invoice_id == null) {
            $this->_logger->debug("ERROR - No magento invoice id for this invoice yet, it will be lost when it is sent to apruve!");
        }

        /* prepare invoice data */
        $data = json_encode([
            'invoice' => [
                'amount_cents' => $this->convertPrice($invoice->getBaseGrandTotal()),
                'currency' => $this->getCurrency(),
                'shipping_cents' => $this->convertPrice($invoice->getBaseShippingAmount()),
                'tax_cents' => $this->convertPrice($invoice->getBaseTaxAmount()),
                // 'merchant_notes' => $comment->getComment(),
                'merchant_invoice_id' => $magento_invoice_id,
                'invoice_items' => $items,
                'issue_on_create' => true
            ]
        ]);

        return $data;
    }

    protected function convertPrice($price)
    {
        return (int)($price * 100);
    }

    public function getCurrency()
    {
        return self::CURRENCY;
    }

    protected function _getDiscountItem($object)
    {
        $discountItem = [];
        $discountItem['quantity'] = 1;
        $discountItem['currency'] = $this->getCurrency();
        $discountItem['description'] = __('Cart Discount');
        $discountItem['sku'] = __('Discount');
        $discountItem['title'] = __('Discount');

        if ($object instanceof Mage_Sales_Model_Quote) {
            $discountAmount = $this->convertPrice($object->getBaseSubtotal() - $object->getBaseSubtotalWithDiscount());
        } elseif ($object instanceof Mage_Sales_Model_Order) {
            $discountAmount = $this->convertPrice($object->getBaseDiscountAmount());
        } elseif ($object instanceof Mage_Sales_Model_Order_Invoice) {
            $discountAmount = $this->convertPrice($object->getBaseDiscountAmount());
        } else {
            return false;
        }
        if ($discountAmount) {
            $discountAmount = -1 * abs($discountAmount);
            $discountItem['price_ea_cents'] = $discountAmount;
            $discountItem['price_total_cents'] = $discountAmount;

            return $discountItem;
        } else {
            return false;
        }
    }

    protected function _apruve($action, $token, $data = '', $object = 'orders', $requestType = 'POST')
    {
        $url = sprintf("https://%s.apruve.com/api/v4/%s", $this->method->getConfigData('mode'), $object);
        if (!empty($token)) {
            // Here we need to remove "-void", etc.
            $token = preg_replace('/\-.*/', '', $token);
            $url = sprintf($url . "/%s", $token);
        }
        if (!empty($action)) {
            $url = sprintf($url . "/%s", $action);
        }

        $this->_logger->debug("InvoiceObserver::_apruve called. Sending $requestType to $url");
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
                'apruve-api-key: ' . $this->method->getConfigData('api_key'),
                'content-type: application/json'
            ]
        ]);
        $response = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $this->_logger->debug("Got a response with status code $httpStatus");
        if ($error) {
            $parsed = json_decode($response);
            throw new \Magento\Framework\Exception\LocalizedException(__('Bad Response from Apruve:' . $parsed->error));
        }
        if ($httpStatus == 200 || $httpStatus == 201) {
            return json_decode($response);
        }
        return false;
    }
}