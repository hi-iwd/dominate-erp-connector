<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Result assembler for product import.
 * Handles consistent result formatting for all import outcomes.
 */
class ResultAssembler
{
    /**
     * Create success result for product.
     *
     * @param ProductInterface $product
     * @param string $action 'created' or 'updated'
     * @return array
     */
    public function success(ProductInterface $product, string $action): array
    {
        return [
            'sku' => $product->getSku(),
            'status' => 'success',
            'action' => $action,
            'product_id' => (int)$product->getId(),
            'type_id' => $product->getTypeId(),
        ];
    }

    /**
     * Create skipped result for product.
     *
     * @param string $sku
     * @param string $message
     * @return array
     */
    public function skipped(string $sku, string $message): array
    {
        return [
            'sku' => $sku,
            'status' => 'skipped',
            'action' => 'skipped',
            'message' => $message,
        ];
    }

    /**
     * Create failed result for product.
     *
     * @param string $sku
     * @param string $errorMessage
     * @param string|null $errorCode
     * @return array
     */
    public function failed(string $sku, string $errorMessage, ?string $errorCode = 'import_error'): array
    {
        return [
            'sku' => $sku,
            'status' => 'failed',
            'action' => 'failed',
            'error_code' => $errorCode,
            'message' => $errorMessage,
        ];
    }
}
