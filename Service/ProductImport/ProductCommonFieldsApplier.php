<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Magento\Catalog\Api\Data\ProductInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies common product fields (name, website, weight, price, description, stock).
 * Shared between SimpleProductBuilder and ConfigurableProductBuilder.
 */
class ProductCommonFieldsApplier
{
    /**
     * @var ProductDefaultsResolver
     */
    private ProductDefaultsResolver $defaultsResolver;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ProductCommonFieldsApplier constructor.
     *
     * @param ProductDefaultsResolver $defaultsResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductDefaultsResolver $defaultsResolver,
        LoggerInterface $logger
    ) {
        $this->defaultsResolver = $defaultsResolver;
        $this->logger = $logger;
    }

    /**
     * Resolve product name with SKU fallback.
     *
     * @param array $item
     * @return string
     */
    public function resolveName(array $item): string
    {
        return isset($item['name']) && trim((string)$item['name']) !== ''
            ? (string)$item['name']
            : (string)$item['sku'];
    }

    /**
     * Apply common fields to a product (name, website, weight, price, description, stock).
     *
     * @param ProductInterface $product
     * @param array $item
     * @param string $logContext Context for logging (e.g., 'product' or 'configurable product')
     * @return void
     */
    public function applyCommonFields(ProductInterface $product, array $item, string $logContext = 'product'): void
    {
        // Set name with SKU fallback
        $product->setName($this->resolveName($item));

        // Assign to default website
        try {
            $websiteIds = [$this->defaultsResolver->getDefaultWebsiteId()];
            $product->setWebsiteIds($websiteIds);
        } catch (\Exception $e) {
            $this->logger->warning('[Dominate_ErpConnector] Failed to set website IDs for ' . $logContext, [
                'sku' => $item['sku'],
                'error' => $e->getMessage(),
            ]);
        }

        // Set weight if provided
        if (isset($item['weight'])) {
            $product->setWeight((float)$item['weight']);
        }

        // Set price if provided
        if (isset($item['price'])) {
            $product->setPrice((float)$item['price']);
        }

        // Set description if provided
        if (isset($item['description']) && trim((string)$item['description']) !== '') {
            $product->setDescription((string)$item['description']);
        }

        // Set stock data if provided
        if (isset($item['qty'])) {
            $qty = (float)$item['qty'];
            $product->setStockData([
                'qty' => $qty,
                'is_in_stock' => $qty > 0,
                'manage_stock' => true,
            ]);
        }
    }
}
