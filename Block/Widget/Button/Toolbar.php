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
        $this->logger->debug('Constructing toolbar');
    }

    public function beforePushButtons(
        ToolbarContext $toolbar,
        \Magento\Framework\View\Element\AbstractBlock $context,
        \Magento\Backend\Block\Widget\Button\ButtonList $buttonList
    ) {
        $this->logger->debug('In beforePushButtons');
        $this->logger->debug('Context has class ' . get_class($context));
        $this->logger->debug('ButtonList: ' . print_r($buttonList->getItems(), true));
        // Only change the toolbar we want to change (the one for orders in the admin)
        if (!$context instanceof \Magento\Sales\Block\Adminhtml\Order\View) {
            $this->logger->debug('Toolbar was not the admin sales toolbar, no-oping');
            return [$context, $buttonList];
        }
        $order = $context->getOrder();
        // Only change the toolbar if it's an apruve order
        if($order->getPayment()->getMethod() != 'apruve') {
            $this->logger->debug('Order was not an apruve order, no-oping');
            return [$context, $buttonList];
        }
        
        // Remove the invoice button (which doesn't work with our flow)
        $this->logger->debug('Removing invoice from button list');
        $buttonList->remove('order_invoice');
        return [$context, $buttonList];
    }
}