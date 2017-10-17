<?php

namespace Apruve\Payment\Observer;

use Magento\Framework\Event\ObserverInterface;

class ShipmentObserver implements ObserverInterface
{
	const CODE = 'apruve';
	const DISCOUNT = 'Discount';
	const CURRENCY = 'USD';
	const SHIPPING_PARTIAL = 'partial';
	const SHIPPING_COMPLETE = 'fulfilled';

	protected $method;
	protected $helper;
	protected $order;
	protected $shipment;
	protected $firstShipment;
	protected $invoiceService;
	protected $invoiceInterface;

	public function __construct(
		\Magento\Payment\Helper\Data $paymentHelper,
		\Apruve\Payment\Helper\Data $helper,
		\Magento\Framework\DB\Transaction $transaction,
		\Magento\Sales\Model\Service\InvoiceService $invoiceService,
		\Magento\Sales\Api\Data\OrderInterface $order,
		\Magento\Sales\Api\Data\InvoiceInterface $invoiceInterface
	) {
		$this->method            = $paymentHelper->getMethodInstance(self::CODE);
		$this->_helper           = $helper;
		$this->_invoiceService   = $invoiceService;
		$this->_transaction      = $transaction;
		$this->_order            = $order;
		$this->_invoiceInterface = $invoiceInterface;
	}

	public function execute(\Magento\Framework\Event\Observer $observer)
	{
		$this->_shipment = $observer->getEvent()->getShipment();
		$this->_order    = $this->_shipment->getOrder();
		$payment = $this->_order->getPayment();

		if($payment->getMethod() != 'apruve'){
			return true;
		}

		// Calculate shipped quantity
		$itemQty = $this->_getShippedItemQty($this->_shipment);

		// Create invoice
		$this->_invoice = $this->_createInvoiceFromShipment($itemQty);
		if (! $this->_invoice) {
			throw new \Magento\Framework\Validator\Exception(__('Problem creating invoice in Apruve.'));
		}

		if ($this->_order->getShipmentsCollection()->getSize() <= 1) {
			$this->_firstShipment = 1;
		} else {
			$this->_firstShipment = 0;
		}

		if ($this->_order->getPayment()->getMethod() != self::CODE) {
			return;
		}

		// Create Shipment

		$token  = $this->_invoice->getTransactionId();
		$tracks = $this->_shipment->getAllTracks();
		$track  = end($tracks);
		$amount = 0;
		$tax    = 0;

		foreach ($this->_shipment->getAllItems() as $item) {
			$amount += $item->getPrice();
			$tax    += ( $item->getPriceInclTax() - $item->getPrice() );
		}

		$data = [];

		// First shipment holds complete amounts of tax_cents and shipping_cents
		if ($this->_firstShipment) {
			$data['tax_cents']      = $this->_order->getTaxAmount() * 100;
			$data['shipping_cents'] = $this->_order->getShippingAmount() * 100;
		} else {
			$data['tax_cents']      = 0;
			$data['shipping_cents'] = 0;
		}

		$data['amount_cents']         = $this->_getPartialShipmentAmount() * 100;
		$data['currency']             = $this->_order->getData('base_currency_code');
		$data['shipper']              = $track ? $track->getTitle() : '';
		$data['tracking_number']      = $track ? $track->getTrackNumber() : '';
		$data['shipped_at']           = date(DATE_ISO8601, strtotime($this->_shipment->getCreatedAt()));
		$data['delivered_at']         = '';
		$data['merchant_notes']       = $this->_shipment->getCustomerNote();
		$data['status']               = $this->_getShipmentStatus();
		$data['merchant_shipment_id'] = $this->_shipment->getIncrementId();
		$data['shipment_items']       = $this->_getShipmentItems($itemQty);

		$response = $this->_processShipment($token, json_encode($data));

		if (! isset($response->id)) {
			$errorMessage = isset($response->errors) ? $response->errors[0]->title : 'Error Creating Shipment';
			throw new \Magento\Framework\Validator\Exception(__($errorMessage));
		}

		# If all products have been shipped, mark as complete
		$this->_isOrderComplete();
	}

	protected function _getShippedItemQty($shipment)
	{
		$qtys = [];
		foreach ($shipment->getAllItems() as $item) {
			$orderItem                   = $item->getOrderItem();
			$qtys[ $orderItem->getId() ] = $item->getQty();
		}

		return $qtys;
	}

	protected function _createInvoiceFromShipment($itemQty)
	{
		$token = $this->_order->getPayment()->getLastTransId();

		try {
			if ($token && $this->_order->getId() && $this->_order->getPayment()->getMethod() == self::CODE && $this->_order->canInvoice()) {
				// Create invoice
				$invoice = $this->_invoiceService->prepareInvoice($this->_order, $itemQty);
				$invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
				$invoice->register();
				$transactionSave = $this->_transaction->addObject(
					$invoice
				)->addObject(
					$invoice->getOrder()
				);
				$transactionSave->save();

				$data = $this->_getInvoiceData($invoice);

				$response = $this->_apruve('invoices', $token, $data);
				if (! isset($response->id)) {
					throw new \Magento\Framework\Validator\Exception(__('Apruve invoice creation error.'));
				}

				$invoice->setTransactionId($response->id);
				return $invoice;
			}
		} catch (\Exception $e) {
			throw new \Magento\Framework\Validator\Exception(__('Apruve invoice creation error'));
		}

		throw new \Magento\Framework\Validator\Exception(__('Apruve invoice creation error'));
	}

	protected function _getInvoiceData($invoice, $itemQty = null)
	{
		$invoiceItems = $invoice->getAllItems();

		$items = [];
		foreach ($invoiceItems as $invoiceItem) {
			$orderItem = $invoiceItem->getOrderItem();
			/* create invoice item for apruve */
			$item                      = [];
			$item['price_ea_cents']    = $this->convertPrice($invoiceItem->getBasePrice());
			$item['quantity']          = intval($invoiceItem->getQty());
			$item['price_total_cents'] = $this->convertPrice($invoiceItem->getBaseRowTotal());
			$item['currency']          = $this->getCurrency();
			$item['title']             = $invoiceItem->getName();
			$item['merchant_notes']    = $invoiceItem->getAdditionalData();
			$item['description']       = $invoiceItem->getDescription();
			$item['sku']               = $invoiceItem->getSku();
			$item['variant_info']      = $invoiceItem->getProductOptions();
			$item['vendor']            = '';
			/* add invoice item to $items array */
			$items[] = $item;
		}
		// get discount line item
		if (( $discountItem = $this->_getDiscountItem($invoice) )) {
			$items[] = $discountItem;
		}

		/* latest shipment comment */
		// $comment = $this->_getInvoiceComment($invoice);

		/* prepare invoice data */
		$data = json_encode([
			'invoice' => [
				'amount_cents'        => $this->convertPrice($invoice->getBaseGrandTotal()),
				'currency'            => $this->getCurrency(),
				'shipping_cents'      => $this->convertPrice($invoice->getBaseShippingAmount()),
				'tax_cents'           => $this->convertPrice($invoice->getBaseTaxAmount()),
				// 'merchant_notes' => $comment->getComment(),
				'merchant_invoice_id' => $invoice->getIncrementId(),
				'invoice_items'       => $items,
				'issue_on_create'     => true
			]
		]);

		return $data;
	}

	protected function convertPrice($price)
	{
		return $price * 100;
	}

	public function getCurrency()
	{
		return self::CURRENCY;
	}

	protected function _getDiscountItem($object)
	{
		$discountItem                = [];
		$discountItem['quantity']    = 1;
		$discountItem['currency']    = $this->getCurrency();
		$discountItem['description'] = __('Cart Discount');
		$discountItem['sku']         = __('Discount');
		$discountItem['title']       = __('Discount');

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
			$discountAmount                    = - 1 * abs($discountAmount);
			$discountItem['price_ea_cents']    = $discountAmount;
			$discountItem['price_total_cents'] = $discountAmount;

			return $discountItem;
		} else {
			return false;
		}
	}

	protected function _apruve($action, $token, $data = '', $object = 'orders', $requestType = 'POST')
	{
		$url = sprintf("https://%s.apruve.com/api/v4/%s", $this->method->getConfigData('mode'), $object);

		if (! empty($token)) {
			// Here we need to remove "-void", etc.
			$token = preg_replace('/\-.*/', '', $token);
			$url   = sprintf($url . "/%s", $token);
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
				'apruve-api-key: ' . $this->method->getConfigData('api_key'),
				'content-type: application/json'
			]
		]);


		$response   = curl_exec($curl);
		$httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error      = curl_error($curl);
		curl_close($curl);

		if ($error) {
			$parsed = json_decode($response);
			throw new \Magento\Framework\Exception\LocalizedException(__('Bad Response from Apruve:' . $parsed->error));
		}

		if ($httpStatus == 200 || $httpStatus == 201) {
			return json_decode($response);
		}

		return false;
	}

	protected function _getPartialShipmentAmount()
	{
		$totalsAmount = $this->_order->getGrandTotal() - $this->_order->getSubtotal();
		$amount       = 0;

		foreach ($this->_shipment->getAllItems() as $item) {
			$amount += $item->getPrice() * intval($item->getQty());
		}

		if ($this->_firstShipment) {
			return $amount + $totalsAmount;
		}

		return $amount;
	}

	protected function _getShipmentStatus()
	{
		$qtyOrdered = intval($this->_order->getTotalQtyOrdered());
		$qtyShipped = intval($this->_shipment->getTotalQty());

		if ($qtyOrdered > $qtyShipped) {
			return self::SHIPPING_PARTIAL;
		}

		return self::SHIPPING_COMPLETE;
	}

	protected function _getShipmentItems($itemQty)
	{
		$data = [];

		$items = $this->_shipment->getAllItems();
		foreach ($items as $item) {
			$product      = $item->getOrderItem()->getProduct();
			$shipmentItem = [];

			$shipmentItem['price_ea_cents']    = $item->getPrice() * 100;
			$shipmentItem['quantity']          = intval($item->getQty());
			$shipmentItem['price_total_cents'] = $item->getPrice() * intval($item->getQty()) * 100;
			$shipmentItem['currency']          = $this->_shipment->getOrder()->getData('order_currency_code');
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
		if ($discount < 0 && $this->_firstShipment) {
			$discountItem                      = [];
			$discountItem['price_ea_cents']    = (int) ( $discount * 100 );
			$discountItem['quantity']          = 1;
			$discountItem['price_total_cents'] = (int) ( $discount * 100 );
			$discountItem['currency']          = $this->_shipment->getOrder()->getData('order_currency_code');
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

	protected function _processShipment($token, $data = '')
	{
		$token = str_replace(
			'-capture',
			'',
			$token
		);

		$apiKey = $this->method->getConfigData('api_key');
		$mode   = $this->method->getConfigData('mode');
		$url    = sprintf("https://%s.apruve.com/api/v4/invoices/%s/shipments", $mode, $token);
		$curl   = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $data,
			CURLOPT_HTTPHEADER     => [
				'accept: application/json',
				'apruve-api-key: ' . $apiKey,
				'content-type: application/json'
			],
		]);

		$response = curl_exec($curl);

		$httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($httpStatus == 200 || $httpStatus == 201) {
			return json_decode($response);
		}

		return false;
	}

	protected function _isOrderComplete()
	{
		# if all product has been shipped
		if (! $this->_order->canInvoice() && ! $this->_order->canShip()) {
			$this->_order->setStatus('complete');
			$this->_order->save();
		}
	}

	protected function _getInvoiceId()
	{
		$invoices = $this->_order->getInvoiceCollection();
		if ($invoices->getSize() < 1) {
			throw new \Magento\Framework\Validator\Exception(__('Please Invoice Order Before Shipping.'));
		}

		$invoice = $invoices->getFirstItem();

		return $invoice->getIncrementId();
	}
}
