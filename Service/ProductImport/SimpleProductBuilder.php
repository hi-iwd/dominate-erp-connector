<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Dominate\ErpConnector\Service\ProductImport\SkipReasons;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Simple product builder service.
 * Handles creation and updates of simple products.
 */
class SimpleProductBuilder
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var ProductInterfaceFactory
     */
    private ProductInterfaceFactory $productFactory;

    /**
     * @var ProductDefaultsResolver
     */
    private ProductDefaultsResolver $defaultsResolver;

    /**
     * @var ResultAssembler
     */
    private ResultAssembler $resultAssembler;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var VariantAttributeApplier
     */
    private VariantAttributeApplier $variantAttributeApplier;

    /**
     * @var ProductCommonFieldsApplier
     */
    private ProductCommonFieldsApplier $commonFieldsApplier;

    /**
     * SimpleProductBuilder constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ProductInterfaceFactory $productFactory
     * @param ProductDefaultsResolver $defaultsResolver
     * @param ResultAssembler $resultAssembler
     * @param LoggerInterface $logger
     * @param VariantAttributeApplier $variantAttributeApplier
     * @param ProductCommonFieldsApplier $commonFieldsApplier
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductInterfaceFactory    $productFactory,
        ProductDefaultsResolver    $defaultsResolver,
        ResultAssembler            $resultAssembler,
        LoggerInterface            $logger,
        VariantAttributeApplier    $variantAttributeApplier,
        ProductCommonFieldsApplier $commonFieldsApplier
    )
    {
        $this->productRepository       = $productRepository;
        $this->productFactory          = $productFactory;
        $this->defaultsResolver        = $defaultsResolver;
        $this->resultAssembler         = $resultAssembler;
        $this->logger                  = $logger;
        $this->variantAttributeApplier = $variantAttributeApplier;
        $this->commonFieldsApplier     = $commonFieldsApplier;
    }

    /**
     * Process simple products (create or update).
     *
     * @param array $items
     * @param array $existingProducts
     * @param bool $updateExisting
     * @param array $variantMappings
     * @param array $optionMaps
     * @return array
     */
    public function processSimpleProducts(array $items, array $existingProducts, bool $updateExisting, array $variantMappings = [], array $optionMaps = []): array
    {
        $results               = [];
        $defaultAttributeSetId = $this->defaultsResolver->getDefaultAttributeSetId();
        $defaultTaxClassId     = $this->defaultsResolver->getDefaultTaxClassId();

        foreach ($items as $item) {
            $sku    = $item['sku'];
            $exists = isset($existingProducts[$sku]);

            // Skip if exists and update not enabled
            if ($exists && !$updateExisting) {
                $results[] = $this->resultAssembler->skipped($sku, SkipReasons::PRODUCT_EXISTS_UPDATE_DISABLED);
                continue;
            }

            try {
                if ($exists) {
                    try {
                        // Update existing product
                        $product = $this->productRepository->get($sku);
                        $this->updateSimpleProduct($product, $item);
                        $this->variantAttributeApplier->assignVariantAttributes($product, $item, $variantMappings, $optionMaps);
                        $product = $this->productRepository->save($product);

                        // Validate product ID after save
                        if ((int)$product->getId() === 0) {
                            $this->logger->error('[Dominate_ErpConnector] Product saved without ID', [
                                'sku' => $sku,
                            ]);
                            $results[] = $this->resultAssembler->failed($sku, 'Product ID missing after save');
                            continue;
                        }

                        $results[] = $this->resultAssembler->success($product, 'updated');
                    } catch (NoSuchEntityException $e) {
                        // Product doesn't actually exist despite indexer saying it does - create instead
                        $this->logger->warning('[Dominate_ErpConnector] Product marked as existing but not found, creating instead', [
                            'sku' => $sku,
                        ]);
                        $exists = false; // Fall through to create logic
                    }
                }

                // Create new product (handles both !$exists and NoSuchEntityException fallback)
                if (!$exists) {
                    $createResult = $this->createAndPersistSimpleProduct(
                        $item,
                        $sku,
                        $defaultAttributeSetId,
                        $defaultTaxClassId,
                        $variantMappings,
                        $optionMaps
                    );
                    if ($createResult !== null) {
                        $results[] = $createResult;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('[Dominate_ErpConnector] Simple product import failed', [
                    'sku'   => $sku,
                    'error' => $e->getMessage(),
                ]);

                $results[] = $this->resultAssembler->failed($sku, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Create and persist a new simple product (extracted DRY method).
     * Validates price, creates product, assigns attributes, saves, and validates ID.
     *
     * @param array $item
     * @param string $sku
     * @param int $attributeSetId
     * @param int $taxClassId
     * @param array $variantMappings
     * @param array $optionMaps
     * @return array|null Result array on success, null if price validation fails
     */
    private function createAndPersistSimpleProduct(
        array  $item,
        string $sku,
        int    $attributeSetId,
        int    $taxClassId,
        array  $variantMappings,
        array  $optionMaps
    ): ?array
    {
        // Require price for new products to avoid Magento validation errors
        if (!array_key_exists('price', $item) || $item['price'] === null || $item['price'] === '') {
            return $this->resultAssembler->failed(
                $sku,
                SkipReasons::PRICE_REQUIRED_FOR_CREATE,
                'missing_required_field'
            );
        }

        // Create new product
        $product = $this->createSimpleProduct($item, $attributeSetId, $taxClassId);
        $this->variantAttributeApplier->assignVariantAttributes($product, $item, $variantMappings, $optionMaps);
        $product = $this->productRepository->save($product);

        // Validate product ID after save
        if ((int)$product->getId() === 0) {
            $this->logger->error('[Dominate_ErpConnector] Product saved without ID', [
                'sku' => $sku,
            ]);
            return $this->resultAssembler->failed($sku, 'Product ID missing after save');
        }

        return $this->resultAssembler->success($product, 'created');
    }

    /**
     * Create a simple product.
     *
     * @param array $item
     * @param int $attributeSetId
     * @param int $taxClassId
     * @return ProductInterface
     */
    public function createSimpleProduct(array $item, int $attributeSetId, int $taxClassId): ProductInterface
    {
        /** @var ProductInterface $product */
        $product = $this->productFactory->create();

        $product->setSku($item['sku']);
        $product->setTypeId(Type::TYPE_SIMPLE);
        $product->setAttributeSetId($attributeSetId);
        $product->setStatus(Status::STATUS_DISABLED);
        $product->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
        $product->setData('tax_class_id', $taxClassId);

        // Apply common fields (name, website, weight, price, description, stock)
        $this->commonFieldsApplier->applyCommonFields($product, $item, 'product');

        return $product;
    }

    /**
     * Update a simple product (safe fields only).
     *
     * @param ProductInterface $product
     * @param array $item
     * @return void
     */
    public function updateSimpleProduct(ProductInterface $product, array $item): void
    {
        // Update safe fields only
        // Update name only when non-empty
        if (isset($item['name']) && trim((string)$item['name']) !== '') {
            $product->setName((string)$item['name']);
        }

        if (isset($item['weight'])) {
            $product->setWeight((float)$item['weight']);
        }

        if (isset($item['price'])) {
            $product->setPrice((float)$item['price']);
        }

        // Update description if provided
        if (isset($item['description']) && trim((string)$item['description']) !== '') {
            $product->setDescription((string)$item['description']);
        }

        // Update stock data using product object
        if (isset($item['qty'])) {
            $qty = (float)$item['qty'];
            $product->setStockData([
                'qty'         => $qty,
                'is_in_stock' => $qty > 0,
            ]);
        }
    }
}
