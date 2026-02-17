<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Api\InventorySyncInterface;
use Dominate\ErpConnector\Helper\ApiAuthValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Inventory sync implementation.
 * Handles inventory and price updates from ERP (NetSuite) to Magento 2.
 * Uses legacy StockRegistryInterface for inventory updates (single-source MVP).
 * TODO: Migrate to MSI APIs when multi-warehouse support is needed.
 */
class InventorySync implements InventorySyncInterface
{
    /**
     * @var ApiAuthValidator
     */
    private ApiAuthValidator $apiAuthValidator;

    /**
     * @var StockRegistryInterface
     */
    private StockRegistryInterface $stockRegistry;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * InventorySync constructor.
     *
     * @param ApiAuthValidator           $apiAuthValidator
     * @param StockRegistryInterface     $stockRegistry
     * @param ProductRepositoryInterface $productRepository
     * @param LoggerInterface            $logger
     */
    public function __construct(
        ApiAuthValidator           $apiAuthValidator,
        StockRegistryInterface     $stockRegistry,
        ProductRepositoryInterface $productRepository,
        LoggerInterface            $logger
    ) {
        $this->apiAuthValidator   = $apiAuthValidator;
        $this->stockRegistry      = $stockRegistry;
        $this->productRepository  = $productRepository;
        $this->logger             = $logger;
    }

    /**
     * Sync inventory and/or price for products.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $products Array of product updates with keys: sku, qty (optional), price (optional)
     * @return mixed[]
     */
    public function sync(
        string $api_key,
        int    $timestamp,
        string $signature,
        mixed  $products
    ) {
        // Validate API credentials and HMAC signature
        $authResult = $this->apiAuthValidator->validate($api_key, $timestamp, $signature);
        if ($authResult['Error'] === true) {
            return $authResult;
        }

        // Validate products array
        if (empty($products) || !is_array($products)) {
            $this->logger->warning('[Dominate_ErpConnector] Inventory sync failed: invalid_products');
            return ['Error' => true, 'ErrorCode' => 'invalid_products'];
        }

        // TODO: Implement SyncContext service to prevent outbound webhooks when products
        // are updated from ERP. This will be needed when we add outbound product observers.
        // For now, we don't have product observers, so no registry flag is needed.

        $results = [];
        $errors  = [];

        try {
            foreach ($products as $productData) {
                if (!isset($productData['sku']) || empty($productData['sku'])) {
                    $errors[] = 'Missing SKU in product data';
                    continue;
                }

                $sku = (string) $productData['sku'];

                try {
                    // Load product by SKU
                    $product = $this->productRepository->get($sku);

                    // Update price if provided
                    if (isset($productData['price']) && $productData['price'] !== null) {
                        $price = (float) $productData['price'];

                        $product->setPrice($price);

                        $this->productRepository->save($product);
                    }

                    // Update inventory if provided
                    if (isset($productData['qty']) && $productData['qty'] !== null) {
                        $qty = (float) $productData['qty'];

                        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
                        $stockItem->setQty($qty);
                        $stockItem->setIsInStock($qty > 0);

                        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
                    }

                    $results[] = [
                        'sku'    => $sku,
                        'status' => 'success',
                    ];
                } catch (NoSuchEntityException $e) {
                    // Product not found - skip as per requirements
                    $this->logger->info('[Dominate_ErpConnector] Product not found, skipping', [
                        'sku' => $sku,
                    ]);
                    $results[] = [
                        'sku'    => $sku,
                        'status' => 'skipped',
                        'reason' => 'Product not found',
                    ];
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    $errors[] = "SKU {$sku}: {$errorMsg}";
                    $this->logger->error('[Dominate_ErpConnector] Product sync failed', [
                        'sku'   => $sku,
                        'error' => $errorMsg,
                    ]);
                    $results[] = [
                        'sku'    => $sku,
                        'status' => 'failed',
                        'error'  => $errorMsg,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Log unexpected errors
            $this->logger->error('[Dominate_ErpConnector] Unexpected error during inventory sync', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Return response
        if (!empty($errors)) {
            return [
                'Error'   => false,
                'results' => $results,
                'warnings' => $errors,
            ];
        }

        return [
            'Error'   => false,
            'results' => $results,
        ];
    }
}

