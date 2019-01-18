<?php
/*
 * Magento 2.3 requires that post actions tell it that they want to handle CSRF themselves, but this is incompatible with
 * Magneto 2.2 or earlier. To fix this, we dynamically provide two different action base classes depending on the version
 * of Magento.
 *
 * See https://magento.stackexchange.com/a/255082
 */

namespace Apruve\Payment\Controller\updateOrderStatus;

function shouldApplyCSRFPatch()
{
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
    $version = $productMetadata->getVersion();
    preg_match('/2\.(\d)/', $version, $matches); // Try to extract the magento version
    $minor_version_string = $matches[1];
    return ((int) $minor_version_string) >= 3;
}

if (shouldApplyCSRFPatch()) {
    abstract class CSRFAwareAction extends \Magento\Framework\App\Action\Action implements \Magento\Framework\App\CsrfAwareActionInterface
    {
        public function createCsrfValidationException(RequestInterface $request)
        {
            return null;
        }

        public function validateForCsrf(RequestInterface $request)
        {
            return true;
        }
    }
} else {
    abstract class CSRFAwareAction extends \Magento\Framework\App\Action\Action { }
}