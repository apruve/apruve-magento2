<?php

namespace Apruve\Payment\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\Status\State;

class InstallData implements InstallDataInterface {

	public function install( ModuleDataSetupInterface $setup, ModuleContextInterface $context ) {
		/**
		 * Prepare database for install
		 */
		$setup->startSetup();

		$setup->getConnection()
		      ->insertArray( $setup->getTable( 'sales_order_status' ), [
			      'status',
			      'label'
		      ], [ 'status' => 'apruve_buyer_approved', 'label' => 'Apruve Buyer Approved' ] );

		$setup->getConnection()
		      ->insertArray( $setup->getTable( 'sales_order_status_state' ), [
			      'status',
			      'label',
			      'is_default',
			      'visible_on_front',
			      [
				      'status'           => 'apruve_buyer_approved',
				      'state'            => 'apruve_buyer_approved',
				      'is_default'       => 1,
				      'visible_on_front' => 0
			      ]);

		/**
		 * Prepare database after install
		 */
		$setup->endSetup();
	}
}
