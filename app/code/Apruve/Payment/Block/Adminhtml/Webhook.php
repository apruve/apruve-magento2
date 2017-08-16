<?php

namespace Apruve\Payment\Block\Adminhtml;

class Webhook extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $helper;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Apruve\Payment\Helper\Data $helper,
        $data = []
    ) {
        $this->helper = $helper;

        parent::__construct($context, $data);
    }

    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        return $this->helper->getWebhookUrl();
    }
}
