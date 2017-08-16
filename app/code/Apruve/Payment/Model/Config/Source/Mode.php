<?php

namespace Apruve\Payment\Model\Config\Source;

class Mode implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        $data = [
            [ 'value' => 'test', 'label' => 'Test' ],
            [ 'value' => 'app', 'label' => 'Live' ],
        ];

        return $data;
    }
}
