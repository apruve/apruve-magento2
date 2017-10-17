<?php

namespace Apruve\Payment\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{

    /**
     * {@inheritdoc}
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $sqlForStatus = "DELETE FROM sales_order_status WHERE status = 'apruve_buyer_approved'";
        $setup->getConnection()->query($sqlForStatus);

        $sqlForState = "DELETE FROM sales_order_status_state WHERE status = 'apruve_buyer_approved'";
        $setup->getConnection()->query($sqlForState);

        $setup->endSetup();
    }
}
