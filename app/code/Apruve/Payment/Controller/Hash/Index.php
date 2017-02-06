<?php

namespace Apruve\Payment\Controller\Hash;

class Index extends \Magento\Framework\App\Action\Action
{
    const TEST = true;
    
    protected $order;
    protected $request;
    protected $helper;
    
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Apruve\Payment\Helper\Data $helper
    ) {
        $this->request = $request;
        $this->helper = $helper;
        
        parent::__construct($context);
    }
    
    public function execute() {
        $order = json_decode($this->request->getParam('order'), 1);
        $poNumber = $this->request->getParam('poNumber');
        $order['po_number'] = $poNumber;
        $this->order = $order;
        
        $data = array(
            'order' => json_encode($this->order),
            'secure_hash' => $this->_getSecureHash()	
        );
        
        echo json_encode($data);
    }
    
    protected function _getSecureHash() {
        $order = $this->order;
        $concatString = $this->helper->getApiKey();
        
        foreach ($order as $val) {
            if(!is_array($val)) {
                $concatString .= $val;
            } else {
                foreach($val as $v) {
                    foreach ($v as $s) {
                        $concatString .= $s;
                    }
                }
            }
        }
    
        return hash('sha256', $concatString);
    }
}