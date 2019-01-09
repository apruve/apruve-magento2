<?php
namespace Apruve\Payment\Block\Widget\Button;
 
use Magento\Backend\Block\Widget\Button\Toolbar as ToolbarContext;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Backend\Block\Widget\Button\ButtonList;

class Toolbar
{

    protected $logger;
    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function beforePushButtons(
        ToolbarContext $toolbar,
        \Magento\Framework\View\Element\AbstractBlock $context,
        \Magento\Backend\Block\Widget\Button\ButtonList $buttonList
    ) {
        // Only change the toolbar we want to change (the one for orders in the admin)
        if (!$context instanceof \Magento\Sales\Block\Adminhtml\Order\View) {
            return [$context, $buttonList];
        }
        $order = $context->getOrder();
        // Only change the toolbar if it's an apruve order
        if($order->getPayment()->getMethod() != 'apruve') {
            return [$context, $buttonList];
        }
        
        // Remove the invoice button (which doesn't work with our flow)
        $this->logger->debug('Removing invoice from button list');
        $buttonList->remove('order_invoice');
        return [$context, $buttonList];
    }
}