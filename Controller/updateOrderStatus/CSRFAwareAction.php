<?php
/*
 * Magento 2.3 requires that post actions tell it that they want to handle CSRF themselves, but this is incompatible with
 * Magneto 2.2 or earlier. To fix this, we provide different versions of this class based on the version
 * of Magento.
 *
 * See https://magento.stackexchange.com/a/255082
 */

namespace Apruve\Payment\Controller\updateOrderStatus;

abstract class CSRFAwareAction extends \Magento\Framework\App\Action\Action implements \Magento\Framework\App\CsrfAwareActionInterface
{
    public function createCsrfValidationException(\Magento\Framework\App\RequestInterface $request): ?\Magento\Framework\App\Request\InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(\Magento\Framework\App\RequestInterface $request): ?bool
    {
        return true;
    }
}