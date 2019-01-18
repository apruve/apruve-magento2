<?php

namespace Apruve\Payment\Controller\updateOrderStatus;

class Index extends CSRFAwareAction
{
    protected $order;
    protected $payments;
    protected $resultPageFactory;
    protected $jsonHelper;
    protected $orderManagement;
    protected $invoiceService;
    protected $transaction;
    protected $helper;
    protected $invoice;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Magento\Sales\Model\ResourceModel\Order\Payment\Collection $payments,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\Order\Invoice $invoice,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Apruve\Payment\Helper\Data $helper,
        \Psr\Log\LoggerInterface $logger //log injection
    ) {
    
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonHelper        = $jsonHelper;
        $this->order             = $order;
        $this->invoice = $invoice;
        $this->payments          = $payments;
        $this->invoiceService    = $invoiceService;

        $this->transaction     = $transaction;
        $this->orderManagement = $orderManagement;
        $this->helper          = $helper;
        $this->_logger         = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        $this->_logger->debug("Got a webhook");
        try {
            if (! $this->_validate()) {
                return;
            };

            $data   = $this->_getData();
            $action = $data->event;
            $success = false;

            switch ($action) {
                case 'invoice.closed':
                case 'invoice.funded':
                    $success = $this->_invoiceFunded($data);
                    break;
                // cancelled is used in docs, canceled live
                case 'order.canceled':
                case 'order.cancelled':
                    $success = $this->_cancelOrder($data);
                    break;

                case 'payment_term.accepted':
                    $success = $this->_paymentTermAccepted($data);
                    break;
            }

            if ($success) {
                http_response_code(200);
            } else {
                http_response_code(404);
            }
        } catch (\Exception $e) {
            $this->_logger->debug("Got an exception while processing a webhook: " . $e->getMessage());
            throw $e;
        }
    }

    protected function _validate()
    {
        if ($this->_validateWebhookUrl()) {
            return true;
        }

        $hash = $this->_getApruveHeader();
        $data = $this->_getRawData();

        return $hash == hash('sha256', $data);
    }

    protected function _validateWebhookUrl()
    {
        $hash = $this->_request->getServer('QUERY_STRING');

        return $this->helper->validateWebhookHash($hash);
    }

    protected function _getApruveHeader()
    {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if ($name == 'X-Apruve-Signature') {
                return $value;
            }
        }

        return false;
    }

    protected function _getRawData()
    {
        return file_get_contents('php://input');
    }

    protected function _getData()
    {
        $data = trim($this->_getRawData());

        return json_decode($data);
    }

    protected function _invoiceFunded($data)
    {
        $this->_logger->debug('apruve_invoiceFunded');

        try {
            $transactionId = $data->entity->order_id;
            $apruve_order_uuid = $data->entity->order_id;
            $apruve_invoice_uuid = $data->entity->id;
            $apruve_invoice = $this->helper->runApruveGetRequest($data->entity->links->self); // Get a full invoice
            $magento_invoice_increment_id = $apruve_invoice->merchant_invoice_id;
            if ($magento_invoice_increment_id == null) {
                $this->_logger->debug("Null magento invoice id returned for apruve invoice $apruve_invoice_uuid. No way to find it in magento");
                return false;
            }

            $this->_logger->debug('Apruve order uuid: ' . $apruve_order_uuid);
            $this->_logger->debug('Apruve invoice uuid: ' . $apruve_invoice_uuid);
            $this->_logger->debug('Magento invoice increment id: ' . $magento_invoice_increment_id);

            $this->payments->addAttributeToFilter('last_trans_id', $transactionId);
            if (! $this->payments->getSize()) {
                return;
            }

            $orderId = $this->payments->getFirstItem()->getParentId(); # The parent of a payment is an order
            $this->order->load($orderId);
            $this->_logger->debug('Loaded order through a payment');

            $this->invoice->loadByIncrementId($magento_invoice_increment_id);
            $this->_logger->debug('Loaded invoice via increment id: ' . $magento_invoice_increment_id);
            if ($this->invoice->canCapture()) {
                $this->_logger->debug('Invoice can be captured. Capturing');
                $this->invoice->capture();
                $this->_logger->debug('Captured');
            } else {
                $this->_logger->debug("ERROR: Invoice $magento_invoice_increment_id cannot be captured in response to apruve funding webhook");
            }
            return $this->transaction->addObject($this->invoice)->save();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->debug("Cannot find this entity in Magento2 - possible duplicate webhook - invoiceFunded - TransactionId: {$transactionId}");
        } catch (\Exception $e) {
            $this->_logger->debug('Caught exception: ', $e->getMessage(), "\n");
        }

        return $this;
    }

    protected function _cancelOrder($data)
    {
        $this->_logger->debug('apruve_cancelOrder');

        try {
            $transactionId = $data->entity->id;
            $this->payments->addAttributeToFilter('last_trans_id', $transactionId);
            if (! $this->payments->getSize()) {
                return;
            }

            $orderId = $this->payments->getFirstItem()->getParentId();
            return $this->orderManagement->cancel($orderId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->info("Cannot find this entity in Magento2 - possible duplicate webhook - cancelOrder - TransactionId: {$transactionId}");
        } catch (\Exception $e) {
            $this->_logger->info('Caught exception: ', $e->getMessage(), "\n");
        }
    }

    protected function _paymentTermAccepted($data)
    {
        $apruve_order_token = $data->entity->purchase_order_id;
        // Apruve doesn't send back our id for some reason
        $apruve_order = $this->helper->runApruveGetRequest($data->entity->links->order);
        $magento_order_increment_id = $apruve_order->merchant_order_id;
        $this->_logger->debug("apruve_paymentTermAccepted webhook called for apruve order $apruve_order_token with magento increment $magento_order_increment_id");

        try {
            $this->order->loadByIncrementId($magento_order_increment_id);
            if ($this->order->getEntityId() == null) {
                $this->_logger->debug("Cannot find this entity in Magento2 - possible duplicate webhook - paymentTermAccepted - MerchantOrderId: {$data->entity->merchant_order_id}");
                return true; // Quietly die and return a 200 code
            }
            $this->order->setStatus('apruve_buyer_approved');
            return $this->order->save();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->debug("Cannot find this entity in Magento2 - possible duplicate webhook - paymentTermAccepted - MerchantOrderId: {$data->entity->merchant_order_id}");
        } catch (\Exception $e) {
            $this->_logger->debug('Caught exception: ', $e->getMessage(), "\n");
            return false;
        }
        return true;
    }
}
