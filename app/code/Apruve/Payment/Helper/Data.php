<?php

namespace Apruve\Payment\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const CODE = 'apruve';
    protected $_method;
    protected $_customer;
    /**
     *
     * @param Magento\Framework\App\Helper\Context $context            
     * @param Magento\Store\Model\StoreManagerInterface $storeManager            
     * @param Magento\Catalog\Model\Product $product            
     * @param Magento\Framework\Data\Form\FormKey $formKey
     *            $formkey,
     * @param Magento\Quote\Model\Quote $quote,            
     * @param Magento\Customer\Model\CustomerFactory $customerFactory,            
     * @param Magento\Sales\Model\Service\OrderService $orderService,            
     */
    protected $paymentHelper;
    public function __construct(\Magento\Framework\App\Helper\Context $context, \Magento\Store\Model\StoreManagerInterface $storeManager, \Magento\Catalog\Model\Product $product, \Magento\Framework\Data\Form\FormKey $formkey, \Magento\Quote\Model\QuoteFactory $quote, \Magento\Quote\Model\QuoteManagement $quoteManagement, \Magento\Customer\Model\CustomerFactory $customerFactory, \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository, \Magento\Sales\Model\Service\OrderService $orderService, 

    \Magento\Payment\Helper\Data $paymentHelper) {
        $this->_storeManager = $storeManager;
        $this->_product = $product;
        $this->_formkey = $formkey;
        $this->quote = $quote;
        $this->quoteManagement = $quoteManagement;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->orderService = $orderService;
        $this->paymentHelper = $paymentHelper;
        
        $this->_method = $paymentHelper->getMethodInstance(self::CODE);
        parent::__construct($context);
    }
    protected function _getCustomer($id) {
        $apiKey = $this->_method->getConfigData('api_key');
        $mode = $this->_method->getConfigData('mode');
        $url = sprintf("https://%s.apruve.com/api/v4/users/%s", $mode, $id);
        $curl = curl_init();
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "accept: application/json",
                "apruve-api-key: " . $apiKey
            )
        ));
        
        $response = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpStatus != 404) {
            $response = json_decode($response, 1);
        } else {
            return false;
        }
        
        if (isset($response ['errors'])) {
            return false;
        }
        
        return $response;
    }
    public function createOrder($data) {
        
        $this->_customer = $this->_getCustomer($data->entity->customer_id);
        if (!$this->_customer) {
            return $this->error();
        }
        $data = (array)$data;
        
        $data['email'] = $this->_customer['email'];
        
        $store = $this->_storeManager->getStore();
        $websiteId = $this->_storeManager->getStore()->getWebsiteId();
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($data['email']); // load customet by email address
        
        if (!$customer->getEntityId()) {
            return $this->error();
            /*
             * //If not avilable then create this customer
             * $customer->setWebsiteId($websiteId)
             * ->setStore($store)
             * ->setFirstname($data['shipping_address']['firstname'])
             * ->setLastname($data['shipping_address']['lastname'])
             * ->setEmail($data['email'])
             * ->setPassword($data['email']);
             * $customer->save();
             */
        }
        
        $quote = $this->quote->create(); // Create object of quote
        $quote->setStore($store); // set store for which you create quote
        
        // if you have allready buyer id then you can load customer directly
        $customer = $this->customerRepository->getById($customer->getEntityId());
        $quote->setCurrency();
        $quote->assignCustomer($customer); // Assign quote to customer

        //\Zend_Debug::dump($data); exit;
        // add items in quote
        foreach ($data['entity']->order_items as $item) {
            //\Zend_Debug::dump($item); exit;
            $product = $this->_product->loadByAttribute('sku', $item->sku);
            $product->setPrice($item->price_ea_cents);
            $quote->addProduct($product, intval($item->quantity));
        }
        
        // Set Address to quote
        $quote->getBillingAddress()->addData($customer->get);
        $quote->getShippingAddress()->addData($data['shipping_address']);
        
        // Collect Rates and Set Shipping & Payment Method
        
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('freeshipping_freeshipping'); // shipping method
        $quote->setPaymentMethod(self::CODE); // payment method
        $quote->setInventoryProcessed(false); // not effetc inventory
        $quote->save(); // Now Save quote and your quote is ready
                        
        // Set Sales Order Payment
        $quote->getPayment()->importData([
            'method' => self::CODE
        ]);
        
        // Collect Totals & Save Quote
        $quote->collectTotals()->save();
        
        // Create Order From Quote
        $order = $this->quoteManagement->submit($quote);
        
        $order->setEmailSent(0);
        $increment_id = $order->getRealOrderId();
        if ($order->getEntityId()) {
            $result ['order_id'] = $order->getRealOrderId();
        } else {
            $result = [
                'error' => 1,
                'msg' => 'Your custom message'
            ];
        }
        return $result;
    }
    
    public function getApiKey() {
        return $this->_method->getConfigData('api_key');
    }
    
    protected function error() {
        /**
         * Todo: Log data
         */
        return false;
    }
}