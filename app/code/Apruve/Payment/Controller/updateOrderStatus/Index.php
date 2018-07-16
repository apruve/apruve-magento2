<?php

namespace Apruve\Payment\Controller\updateOrderStatus;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $order;
    protected $payments;
    protected $resultPageFactory;
    protected $jsonHelper;
    protected $orderManagement;
    protected $invoiceService;
    protected $transaction;
    protected $helper;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Magento\Sales\Model\ResourceModel\Order\Payment\Collection $payments,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Apruve\Payment\Helper\Data $helper,
        \Psr\Log\LoggerInterface $logger //log injection
    ) {
    
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonHelper        = $jsonHelper;
        $this->order             = $order;
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
        if (! $this->_validate()) {
            return;
        };

        $data   = $this->_getData();
        $action = $data->event;
        $success = false;

        switch ($action) {
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
            $this->payments->addAttributeToFilter('last_trans_id', $transactionId);
            if (! $this->payments->getSize()) {
                return;
            }

            $orderId = $this->payments->getFirstItem()->getParentId();
            $this->order->load($orderId);

            // Create Invoice
            if ($this->order->canInvoice()) {
                $payment = $this->order->getPayment();
                $payment->setLastTransId($data->entity->id);
                $payment->setTransactionId($data->entity->id);

                $invoice = $this->invoiceService->prepareInvoice($this->order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $invoice->save();

                $transactionSave = $this->transaction
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->addObject($payment);

                return $transactionSave->save();
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->info("Cannot find this entity in Magento2 - possible duplicate webhook - invoiceFunded - TransactionId: {$transactionId}");
        } catch (\Exception $e) {
            $this->_logger->info('Caught exception: ', $e->getMessage(), "\n");
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
        $this->_logger->debug('apruve_paymentTermAccepted');

        try {
            $this->order->loadByIncrementId($data->entity->merchant_order_id);
            $this->order->setStatus('apruve_buyer_approved');
            return $this->order->save();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->info("Cannot find this entity in Magento2 - possible duplicate webhook - paymentTermAccepted - MerchantOrderId: {$data->entity->merchant_order_id}");
        } catch (\Exception $e) {
            $this->_logger->info('Caught exception: ', $e->getMessage(), "\n");
        }
        return $this;
    }
}
