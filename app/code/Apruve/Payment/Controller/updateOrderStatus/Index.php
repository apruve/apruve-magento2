<?php

namespace Apruve\Payment\Controller\updateOrderStatus;

class Index extends \Magento\Framework\App\Action\Action {
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
		parent::__construct( $context );
	}

	public function execute() {
		if ( ! $this->_validate() ) {
			return;
		};

		$data   = $this->_getData();
		$action = $data->event;

		switch ( $action ) {
			case 'invoice.closed':
				$this->_invoiceClosed( $data );
				break;

			case 'order.canceled':
				$this->_changeOrderStatus( $data );
				break;

			// cancelled is used in docs, canceled live
			case 'order.canceled':
			case 'order.cancelled':
				$this->_cancelOrder( $data );
				break;

			case 'payment_term.accepted':
				$this->_paymentTermAccepted( $data );
				break;


		}

		header( "HTTP/1.1 200" );
	}

	protected function _validate() {
		if ( $this->_validateWebhookUrl() ) {
			return true;
		}

		$hash = $this->_getApruveHeader();
		$data = $this->_getRawData();

		return $hash == hash( 'sha256', $data );
	}

	protected function _validateWebhookUrl() {
		$hash = $this->_request->getServer( 'QUERY_STRING' );

		return $this->helper->validateWebhookHash( $hash );
	}

	protected function _getApruveHeader() {
		$headers = getallheaders();
		foreach ( $headers as $name => $value ) {
			if ( $name == 'X-Apruve-Signature' ) {
				return $value;
			}
		}

		return false;
	}

	protected function _getRawData() {
		return file_get_contents( 'php://input' );
	}

	protected function _getData() {
		$data = trim( $this->_getRawData() );

		return json_decode( $data );
	}

	protected function _invoiceClosed( $data ) {
		$transactionId = $data->entity->order_id;
		$this->payments->addAttributeToFilter( 'last_trans_id', $transactionId );
		if ( ! $this->payments->getSize() ) {
			return;
		}

		$orderId = $this->payments->getFirstItem()->getParentId();
		$this->order->load( $orderId );

		// Create Invoice
		if ( $this->order->canInvoice() ) {
			$payment = $this->order->getPayment();
			$payment->setLastTransId( $data->entity->id );
			$payment->setTransactionId( $data->entity->id );

			$invoice = $this->invoiceService->prepareInvoice( $this->order );
			$invoice->setRequestedCaptureCase( \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE );
			$invoice->register();
			$invoice->save();

			$transactionSave = $this->transaction
				->addObject( $invoice )
				->addObject( $invoice->getOrder() )
				->addObject( $payment );

			$transactionSave->save();
		}

		return $this;
	}

	protected function _changeOrderStatus( $data ) {
		if ( $this->order && $this->order->getId() && ! $this->order->isCanceled() ) {
			$result = $this->_createInvoice( $this->order->getIncrementId() );

			return $result;
		}

		return false;
	}

	protected function _createInvoice() {
		if ( $orderId ) {
			/** @var Mage_Sales_Model_Order_Invoice_Api $iApi */
			$iApi      = Mage::getModel( 'sales/order_invoice_api' );
			$invoiceId = $iApi->create( $orderId, array() );

			return true;
		}

		return false;
	}

	protected function _cancelOrder( $data ) {
		$transactionId = $data->entity->id;
		$this->payments->addAttributeToFilter( 'last_trans_id', $transactionId );
		if ( ! $this->payments->getSize() ) {
			return;
		}

		$orderId = $this->payments->getFirstItem()->getParentId();
		$this->orderManagement->cancel( $orderId );

		return $this;
	}

	protected function _paymentTermAccepted( $data ) {
		$this->order->loadByIncrementId( $data->entity->merchant_order_id );
		$this->order->setStatus( 'apruve_buyer_approved' );
		$this->order->save();

		return $this;
	}
}
