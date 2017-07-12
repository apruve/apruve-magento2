<?php

namespace Apruve\Payment\Model\Observer;

use Magento\Framework\Event\ObserverInterface;

class Observer implements ObserverInterface {
	const CODE = 'apruve';
	const DISCOUNT = 'Discount';
	const CURRENCY = 'USD';
	const SHIPPING_PARTIAL = 'partial';
	const SHIPPING_COMPLETE = 'fulfilled';

	protected $_method;
	protected $_helper;
	protected $_order;
	protected $_shipment;
	protected $_firstShipment;
	protected $_invoiceService;

	public function __construct(
		\Magento\Payment\Helper\Data $paymentHelper,
		\Apruve\Payment\Helper\Data $helper,
		\Magento\Framework\DB\Transaction $transaction,
		\Magento\Sales\Model\Service\InvoiceService $invoiceService
	) {
		$this->_method         = $paymentHelper->getMethodInstance( self::CODE );
		$this->_helper         = $helper;
		$this->_invoiceService = $invoiceService;
		$this->_transaction    = $transaction;
	}

	public function execute( \Magento\Framework\Event\Observer $observer ) {
		$this->_shipment = $observer->getEvent()->getShipment();
		$this->_order    = $this->_shipment->getOrder();


		if ( $this->_order->getShipmentsCollection()->getSize() <= 1 ) {
			$this->_firstShipment = 1;
		} else {
			$this->_firstShipment = 0;
		}


		if ( $this->_order->getPayment()->getMethod() != self::CODE ) {
			return;
		}

		/**Validate*/
		if ( ! ( $this->_order->getInvoiceCollection()->getSize() ) ) {
			// Create invoice
			$this->_invoice = $this->_createInvoice();
			if(!$this->_invoice)
			{
				throw new \Magento\Framework\Validator\Exception( __( 'Problem creating invoice in Apruve.' ) );
			}
		}

		/**Create Shipment*/
		$token  = $this->_invoice->getTransactionId();
		$tracks = $this->_shipment->getAllTracks();
		$track  = end( $tracks );
		$amount = 0;
		$tax    = 0;

		foreach ( $this->_shipment->getAllItems() as $item ) {
			$amount += $item->getPrice();
			$tax    += ( $item->getPriceInclTax() - $item->getPrice() );
		}

		$data = array();

		// First shipment holds complete amounts of tax_cents and shipping_cents
		if ( $this->_firstShipment ) {
			$data['tax_cents']      = $this->_order->getTaxAmount() * 100;
			$data['shipping_cents'] = $this->_order->getShippingAmount() * 100;
		} else {
			$data['tax_cents']      = 0;
			$data['shipping_cents'] = 0;
		}

		$data['amount_cents']         = $this->_getPartialShipmentAmount() * 100;
		$data['currency']             = $this->_order->getData( 'base_currency_code' );
		$data['shipper']              = $track ? $track->getTitle() : '';
		$data['tracking_number']      = $track ? $track->getTrackNumber() : '';
		$data['shipped_at']           = date( DATE_ISO8601, strtotime( $this->_shipment->getCreatedAt() ) );
		$data['delivered_at']         = '';
		$data['merchant_notes']       = $this->_shipment->getCustomerNote();
		$data['status']               = $this->_getShipmentStatus();
		$data['merchant_shipment_id'] = $this->_shipment->getIncrementId();
		$data['shipment_items']       = $this->_getShipmentItems( $this->_shipment );

		$response = $this->_processShipment( $token, json_encode( $data ) );

		if ( ! isset( $response->id ) ) {
			$errorMessage = isset( $response->errors ) ? $response->errors[0]->title : 'Error Creating Shipment';
			throw new \Magento\Framework\Validator\Exception( __( $errorMessage ) );
		}
	}

	protected function _createInvoice() {
		if ( $this->_order->getId() && $this->_order->getPayment()->getMethod() == self::CODE && $this->_order->canInvoice() ) {

			// Create invoice
			$invoice = $this->_invoiceService->prepareInvoice( $this->_order );
			$invoice->setRequestedCaptureCase( \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE );
			$invoice->register();
			$invoice->save();
			$transactionSave = $this->_transaction->addObject(
				$invoice
			)->addObject(
				$invoice->getOrder()
			);
			$transactionSave->save();

			$data = $this->_getInvoiceData( $invoice );
			$this->_order->getPayment()->capture();
			$token = str_replace( '-capture', '', $this->_order->getPayment()->getTransactionId() );

			$response = $this->_apruve( 'invoices', $token, $data );
			if ( ! isset( $response->id ) ) {
				throw new \Magento\Framework\Validator\Exception( __( 'Apruve order creation error: ' . var_dump( $response ) ) );
			}

			$invoice->setTransactionId($response->id);
			$invoice->save();
			return $invoice;
		}
	}

	function _getInvoiceData( $invoice ) {
		$invoiceItems = $invoice->getAllItems();

		$items = [];
		foreach ( $invoiceItems as $invoiceItem ) {
			$orderItem = $invoiceItem->getOrderItem();
			/* create invoice item for apruve */
			$item                      = [];
			$item['price_ea_cents']    = $this->convertPrice( $invoiceItem->getBasePrice() );
			$item['quantity']          = intval( $invoiceItem->getQty() );
			$item['price_total_cents'] = $this->convertPrice( $invoiceItem->getBaseRowTotal() );
			$item['currency']          = $this->getCurrency();
			$item['title']             = $invoiceItem->getName();
			$item['merchant_notes']    = $invoiceItem->getAdditionalData();
			$item['description']       = $invoiceItem->getDescription();
			$item['sku']               = $invoiceItem->getSku();
			$item['variant_info']      = $orderItem->getProductOptions();
			$item['vendor']            = $this->getVendor( $orderItem );
			/* add invoice item to $items array */
			$items[] = $item;
		}
		// get discount line item
		if ( ( $discountItem = $this->_getDiscountItem( $invoice ) ) ) {
			$items[] = $discountItem;
		}

		/* latest shipment comment */
		// $comment = $this->_getInvoiceComment($invoice);

		/* prepare invoice data */
		$data = json_encode( [
			'invoice' => [
				'amount_cents'        => $this->convertPrice( $invoice->getBaseGrandTotal() ),
				'currency'            => $this->getCurrency(),
				'shipping_cents'      => $this->convertPrice( $invoice->getBaseShippingAmount() ),
				'tax_cents'           => $this->convertPrice( $invoice->getBaseTaxAmount() ),
				// 'merchant_notes' => $comment->getComment(),
				'merchant_invoice_id' => $invoice->getIncrementId(),
				'invoice_items'       => $items,
				'issue_on_create'     => true
			]
		] );

		return $data;
	}

	protected function convertPrice( $price ) {
		return $price * 100;
	}

	public function getCurrency() {
		return self::CURRENCY;
	}

	protected function getVendor( $orderItem ) {
		return 'VENDOR?';
		// $product = $orderItem->getProduct();
		// $attributeCode = Mage::getStoreConfig('payment/apruvepayment/product_vendor');
		// $vendor = $product->getData($attributeCode);
		// return $vendor;
	}

	protected function _getDiscountItem( $object ) {
		$discountItem                = [];
		$discountItem['quantity']    = 1;
		$discountItem['currency']    = $this->getCurrency();
		$discountItem['description'] = __( 'Cart Discount' );
		$discountItem['sku']         = __( 'Discount' );
		$discountItem['title']       = __( 'Discount' );

		if ( $object instanceof Mage_Sales_Model_Quote ) {
			$discountAmount = $this->convertPrice( $object->getBaseSubtotal() - $object->getBaseSubtotalWithDiscount() );
		} elseif ( $object instanceof Mage_Sales_Model_Order ) {
			$discountAmount = $this->convertPrice( $object->getBaseDiscountAmount() );
		} elseif ( $object instanceof Mage_Sales_Model_Order_Invoice ) {
			$discountAmount = $this->convertPrice( $object->getBaseDiscountAmount() );
		} else {
			return false;
		}
		if ( $discountAmount ) {
			$discountAmount                    = - 1 * abs( $discountAmount );
			$discountItem['price_ea_cents']    = $discountAmount;
			$discountItem['price_total_cents'] = $discountAmount;

			return $discountItem;
		} else {
			return false;
		}
	}

	/** Apruve API Manipulation method */
	protected function _apruve( $action, $token, $data = '', $object = 'orders', $requestType = 'POST' ) {
		$url = sprintf( "https://%s.apruve.com/api/v4/%s", $this->_method->getConfigData( 'mode' ), $object );

		if ( ! empty( $token ) ) {
			$url = sprintf( $url . "/%s", $token );
		}

		if ( ! empty( $action ) ) {
			$url = sprintf( $url . "/%s", $action );
		}


		$curl = curl_init();

		curl_setopt_array( $curl, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => $requestType,
			CURLOPT_POSTFIELDS     => $data,
			CURLOPT_HTTPHEADER     => array(
				'accept: application/json',
				'apruve-api-key: ' . $this->_method->getConfigData( 'api_key' ),
				'content-type: application/json'
			)
		) );



		$response   = curl_exec( $curl );
		$httpStatus = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$error      = curl_error( $curl );
		curl_close( $curl );

		if($error)
		{
			$parsed = json_decode($response);
			throw new \Magento\Framework\Exception\LocalizedException( __( 'Bad Response from Apruve:' .  $parsed->error ) );
		}

		if ( $httpStatus == 200 || $httpStatus == 201) {
			return json_decode( $response );
		}

		return false;
	}

	protected function _getInvoiceId() {
		$invoices = $this->_order->getInvoiceCollection();
		if ( $invoices->getSize() < 1 ) {
			throw new \Magento\Framework\Validator\Exception( __( 'Please Invoice Order Before Shipping.' ) );
		}

		$invoice = $invoices->getFirstItem();

		return $invoice->getTransactionId();
	}

	protected function _getPartialShipmentAmount() {
		$totalsAmount = $this->_order->getGrandTotal() - $this->_order->getSubtotal();
		$amount       = 0;

		foreach ( $this->_shipment->getAllItems() as $item ) {
			$amount += $item->getPrice() * intval( $item->getQty() );
		}

		if ( $this->_firstShipment ) {
			return $amount + $totalsAmount;
		}

		return $amount;
	}

	protected function _getShipmentStatus() {
		$qtyOrdered = intval( $this->_order->getTotalQtyOrdered() );
		$qtyShipped = intval( $this->_shipment->getTotalQty() );

		if ( $qtyOrdered > $qtyShipped ) {
			return self::SHIPPING_PARTIAL;
		}

		return self::SHIPPING_COMPLETE;
	}

	protected function _getShipmentItems() {
		$data = array();

		$items = $this->_shipment->getAllItems();
		foreach ( $items as $item ) {
			$product      = $item->getOrderItem()->getProduct();
			$shipmentItem = array();

			$shipmentItem['price_ea_cents']    = $item->getPrice() * 100;
			$shipmentItem['quantity']          = intval( $item->getQty() );
			$shipmentItem['price_total_cents'] = $item->getPrice() * intval( $item->getQty() ) * 100;
			$shipmentItem['currency']          = $this->_shipment->getOrder()->getData( 'order_currency_code' );
			$shipmentItem['title']             = $product->getName();
			$shipmentItem['merchant_notes']    = '';
			$shipmentItem['description']       = $product->getMetaDescription();
			$shipmentItem['sku']               = $item->getSku();
			$shipmentItem['variant_info']      = '';
			$shipmentItem['vendor']            = '';
			$shipmentItem['view_product_url']  = $product->getProductUrl();

			$data[] = $shipmentItem;
		}

		/**Add Discount Item*/
		$discount = $this->_shipment->getOrder()->getDiscountAmount();
		if ( $discount < 0 && $this->_firstShipment ) {
			$discountItem                      = [];
			$discountItem['price_ea_cents']    = (int) ( $discount * 100 );
			$discountItem['quantity']          = 1;
			$discountItem['price_total_cents'] = (int) ( $discount * 100 );
			$discountItem['currency']          = $this->_shipment->getOrder()->getData( 'order_currency_code' );
			$discountItem['title']             = self::DISCOUNT;
			$discountItem['merchant_notes']    = '';
			$discountItem['description']       = self::DISCOUNT;
			$discountItem['sku']               = self::DISCOUNT;
			$discountItem['variant_info']      = '';
			$discountItem['vendor']            = '';
			$discountItem['view_product_url']  = $this->_helper->getStoreUrl();

			$data[] = $discountItem;
		}

		return $data;
	}

	protected function _processShipment( $token, $data = '' ) {
		$apiKey = $this->_method->getConfigData( 'api_key' );
		$mode   = $this->_method->getConfigData( 'mode' );
		$url    = sprintf( "https://%s.apruve.com/api/v4/invoices/%s/shipments", $mode, $token );

		$curl = curl_init();

		curl_setopt_array( $curl, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $data,
			CURLOPT_HTTPHEADER     => array(
				'accept: application/json',
				'apruve-api-key: ' . $apiKey,
				'content-type: application/json'
			),
		) );

		$response = curl_exec( $curl );

		$httpStatus = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );

		if ( $httpStatus == 200 || $httpStatus == 201 ) {
			return json_decode( $response );
		}

		return false;
	}

	public function getConfig() {
		$objectManager         = \Magento\Framework\App\ObjectManager::getInstance();
		$this->_configProvider = $objectManager->create( 'Apruve\Payment\Model\CustomConfigProvider' );

		return $this->_configProvider;
	}

	protected function _createInvoiceFromShipment( $shipment ) {
		// $order = $shipment->getOrder();
		// $invoice = Mage::getModel('sales/order_invoice');
		// try {
		//     $itemQty = $this->_getShippedItemQty($shipment);
		//     $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice($itemQty);
		//     if (!$invoice->getTotalQty()) {
		//         return $invoice;
		//     }
		//     $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::NOT_CAPTURE);
		//     $invoice->register();
		//
		//     $invoice->getOrder()->setCustomerNoteNotify(false);
		//     $invoice->getOrder()->setIsInProcess(true);
		//
		//     $transactionSave = Mage::getModel('core/resource_transaction')
		//         ->addObject($invoice)
		//         ->addObject($invoice->getOrder());
		//
		//     $transactionSave->save();
		// } catch (Mage_Core_Exception $e) {
		//     Mage::helper('apruvepayment')->logException($e->getMessage());
		//     throw new Exception($e->getMessage(), 1);
		// }
		//
		// return $invoice;
	}
}
