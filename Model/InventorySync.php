<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Api\InventorySyncInterface;
use Dominate\ErpConnector\Helper\ApiAuthValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Psr\Log\LoggerInterface;

/**
 * Inventory sync implementation.
 * Handles inventory and price updates from ERP (NetSuite) to Magento 2.
 * Supports two modes:
 * - Single source: qty assigned to 'default' source (when no source_items in payload)
 * - Multi source: per-source qty via SourceItemsSaveInterface (when source_items present in payload)
 */
class InventorySync implements InventorySyncInterface
{
    /**
     * @var ApiAuthValidator
     */
    private ApiAuthValidator $apiAuthValidator;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var SourceItemsSaveInterface
     */
    private SourceItemsSaveInterface $sourceItemsSave;

    /**
     * @var SourceItemInterfaceFactory
     */
    private SourceItemInterfaceFactory $sourceItemFactory;

    /**
     * InventorySync constructor.
     *
     * @param ApiAuthValidator           $apiAuthValidator
     * @param ProductRepositoryInterface $productRepository
     * @param LoggerInterface            $logger
     * @param SourceItemsSaveInterface   $sourceItemsSave
     * @param SourceItemInterfaceFactory $sourceItemFactory
     */
    public function __construct(
        ApiAuthValidator           $apiAuthValidator,
        ProductRepositoryInterface $productRepository,
        LoggerInterface            $logger,
        SourceItemsSaveInterface   $sourceItemsSave,
        SourceItemInterfaceFactory $sourceItemFactory
    ) {
        $this->apiAuthValidator   = $apiAuthValidator;
        $this->productRepository  = $productRepository;
        $this->logger             = $logger;
        $this->sourceItemsSave    = $sourceItemsSave;
        $this->sourceItemFactory  = $sourceItemFactory;
    }

    /**
     * Sync inventory and/or price for products.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $products Array of product updates with keys: sku, qty (optional), price (optional), source_items (optional)
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
                    // Load product by SKU (validates product exists)
                    $product = $this->productRepository->get($sku);

                    // Update price if provided (global, not per-source)
                    if (isset($productData['price']) && $productData['price'] !== null) {
                        $price = (float) $productData['price'];
                        $product->setPrice($price);
                        $this->productRepository->save($product);
                    }

                    // Update inventory: MSI mode or legacy mode
                    if (isset($productData['source_items']) && is_array($productData['source_items'])) {
                        $this->updateInventoryMsi($sku, $productData['source_items']);
                    } elseif (isset($productData['qty']) && $productData['qty'] !== null) {
                        $this->updateInventoryLegacy($sku, (float) $productData['qty']);
                    }

                    $results[] = [
                        'sku'    => $sku,
                        'status' => 'success',
                    ];
                } catch (NoSuchEntityException $e) {
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
            $this->logger->error('[Dominate_ErpConnector] Unexpected error during inventory sync', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

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

    /**
     * Update inventory for a single qty by assigning to the 'default' MSI source.
     */
    private function updateInventoryLegacy(string $sku, float $qty): void
    {
        $this->updateInventoryMsi($sku, [
            ['source_code' => 'default', 'qty' => $qty],
        ]);
    }

    /**
     * Update inventory using MSI SourceItemsSaveInterface for per-source quantities.
     */
    private function updateInventoryMsi(string $sku, array $sourceItemsData): void
    {
        $sourceItems = [];
        foreach ($sourceItemsData as $data) {
            if (!isset($data['source_code'])) {
                continue;
            }

            $sourceItem = $this->sourceItemFactory->create();
            $sourceItem->setSku($sku);
            $sourceItem->setSourceCode($data['source_code']);
            $sourceItem->setQuantity((float) ($data['qty'] ?? 0));
            $sourceItem->setStatus(
                (float) ($data['qty'] ?? 0) > 0 ? 1 : 0
            );
            $sourceItems[] = $sourceItem;
        }

        if (!empty($sourceItems)) {
            $this->sourceItemsSave->execute($sourceItems);
        }
    }
}
