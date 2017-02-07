<?php

namespace Apruve\Payment\Block\Adminhtml;

class Webhook extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $_helper;
    
    
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Apruve\Payment\Helper\Data $helper,
        $data = []
    ) {
        $this->_helper = $helper;
        
        parent::__construct($context, $data);
    }
    
    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        return $this->_helper->getWebhookUrl();
    }
}