<?php

namespace Apruve\Payment\Model\Config\Source;

class Mode implements \Magento\Framework\Option\ArrayInterface {
	public function toOptionArray() {
		$data = array(
			array( 'value' => 'test', 'label' => 'Test' ),
			array( 'value' => 'app', 'label' => 'Live' ),
		);

		return $data;
	}
}