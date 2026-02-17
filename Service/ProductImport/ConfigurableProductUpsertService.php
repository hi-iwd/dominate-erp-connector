<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Handles upsert (get or create) operations for configurable products and their children.
 */
class ConfigurableProductUpsertService
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var SimpleProductBuilder
     */
    private SimpleProductBuilder $simpleProductBuilder;

    /**
     * @var callable Callback to create configurable product
     */
    private $createConfigurableProductCallback;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ConfigurableProductUpsertService constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param SimpleProductBuilder $simpleProductBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        SimpleProductBuilder $simpleProductBuilder,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->simpleProductBuilder = $simpleProductBuilder;
        $this->logger = $logger;
    }

    /**
     * Set callback for creating configurable products.
     *
     * @param callable $callback
     * @return void
     */
    public function setCreateConfigurableProductCallback(callable $callback): void
    {
        $this->createConfigurableProductCallback = $callback;
    }

    /**
     * Validate that item has a valid price for product creation.
     *
     * @param array $item
     * @return bool Returns true if price is valid, false otherwise
     */
    public function validatePriceForCreation(array $item): bool
    {
        return array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '';
    }

    /**
     * Get or create child product, handling existence checks and NoSuchEntityException.
     *
     * @param array $childItem
     * @param bool $childExists
     * @param int $defaultAttributeSetId
     * @param int $defaultTaxClassId
     * @return array{product: ProductInterface|null, exists: bool, error: string|null}
     */
    public function getOrCreateChildProduct(
        array $childItem,
        bool $childExists,
        int $defaultAttributeSetId,
        int $defaultTaxClassId
    ): array {
        $childSku = $childItem['sku'];

        if ($childExists) {
            try {
                $childProduct = $this->productRepository->get($childSku);
                $this->simpleProductBuilder->updateSimpleProduct($childProduct, $childItem);
                return ['product' => $childProduct, 'exists' => true, 'error' => null];
            } catch (NoSuchEntityException $e) {
                // Product doesn't actually exist despite indexer saying it does - create instead
                $this->logger->warning('[Dominate_ErpConnector] Child product marked as existing but not found, creating instead', [
                    'sku' => $childSku,
                ]);
                $childExists = false;
            }
        }

        // Require price for new child products to avoid Magento validation errors
        if (!$this->validatePriceForCreation($childItem)) {
            return [
                'product' => null,
                'exists' => false,
                'error' => SkipReasons::PRICE_REQUIRED_FOR_CREATE
            ];
        }

        $childProduct = $this->simpleProductBuilder->createSimpleProduct($childItem, $defaultAttributeSetId, $defaultTaxClassId);
        return ['product' => $childProduct, 'exists' => $childExists, 'error' => null];
    }

    /**
     * Get or create parent product, handling existence checks and NoSuchEntityException.
     *
     * @param array $parentItem
     * @param bool $parentExists
     * @param int $defaultAttributeSetId
     * @param int $defaultTaxClassId
     * @return array{product: ProductInterface|null, exists: bool, error: string|null}
     */
    public function getOrCreateParentProduct(
        array $parentItem,
        bool $parentExists,
        int $defaultAttributeSetId,
        int $defaultTaxClassId
    ): array {
        $parentSku = $parentItem['sku'];

        if ($parentExists) {
            try {
                $parentProduct = $this->productRepository->get($parentSku);
                $this->simpleProductBuilder->updateSimpleProduct($parentProduct, $parentItem);
                return ['product' => $parentProduct, 'exists' => true, 'error' => null];
            } catch (NoSuchEntityException $e) {
                // Product doesn't actually exist despite indexer saying it does - create instead
                $this->logger->warning('[Dominate_ErpConnector] Parent product marked as existing but not found, creating instead', [
                    'sku' => $parentSku,
                ]);
                $parentExists = false;
            }
        }

        // Require price for new configurable products to avoid Magento validation errors
        if (!$this->validatePriceForCreation($parentItem)) {
            return [
                'product' => null,
                'exists' => false,
                'error' => SkipReasons::PRICE_REQUIRED_FOR_CREATE
            ];
        }

        $parentProduct = ($this->createConfigurableProductCallback)($parentItem, $defaultAttributeSetId, $defaultTaxClassId);
        return ['product' => $parentProduct, 'exists' => $parentExists, 'error' => null];
    }
}
