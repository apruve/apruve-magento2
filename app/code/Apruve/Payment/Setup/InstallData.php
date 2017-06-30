<?php
namespace Apruve\Payment\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;

class InstallData implements InstallDataInterface {

    public function install( ModuleDataSetupInterface $setup, ModuleContextInterface $context ) {
        $installer = $setup;

        $statusTable = $installer->getTable('sales/order_status');
      	$statusStateTable = $installer->getTable('sales/order_status_state');

      // Insert statuses
      	$installer->getConnection()->insertArray($statusTable, array(
      		'status',
      		'label'
      	) , array(
      		array(
      			'status' => 'apruve_buyer_approved',
      			'label' => 'Apruve Buyer Approved'
      		)
      	));

      // Insert states and mapping of statuses to states
      	$installer->getConnection()->insertArray($statusStateTable, array(
      		'status',
      		'state',
      		'is_default'
      	) , array(
      		array(
      			'status' => 'apruve_buyer_approved',
      			'state' => 'apruve_buyer_approved',
      			'is_default' => 1
      		)
      	));
    }
}
