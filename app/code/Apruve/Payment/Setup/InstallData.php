<?php

namespace Apruve\Payment\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order\Status\State;

class InstallData implements InstallDataInterface
{

    /**
     * {@inheritdoc}
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        /**
         * Prepare database for install
         */
        $setup->startSetup();

        $data     = [];
        $statuses = [
            'apruve_buyer_approved' => __('Apruve Buyer Approved')
        ];
        foreach ($statuses as $code => $info) {
            $data[] = [ 'status' => $code, 'label' => $info ];
        }
        $setup->getConnection()
              ->insertArray($setup->getTable('sales_order_status'), [ 'status', 'label' ], $data);
        $stateData   = [];
        $stateData[] = [
            'status'           => 'apruve_buyer_approved',
            'state'            => 'Apruve Buyer Approved',
            'is_default'       => 0,
            'visible_on_front' => 0
        ];

        /**
         * Prepare database after install
         */
        $setup->endSetup();
    }
}
