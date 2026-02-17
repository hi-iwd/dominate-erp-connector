<?php

namespace Dominate\ErpConnector\Service\ProductImport;

/**
 * Product builder service (orchestrator).
 * Delegates to SimpleProductBuilder and ConfigurableProductBuilder.
 */
class ProductBuilder
{
    /**
     * @var SimpleProductBuilder
     */
    private SimpleProductBuilder $simpleProductBuilder;

    /**
     * @var ConfigurableProductBuilder
     */
    private ConfigurableProductBuilder $configurableProductBuilder;

    /**
     * ProductBuilder constructor.
     *
     * @param SimpleProductBuilder $simpleProductBuilder
     * @param ConfigurableProductBuilder $configurableProductBuilder
     */
    public function __construct(
        SimpleProductBuilder $simpleProductBuilder,
        ConfigurableProductBuilder $configurableProductBuilder
    ) {
        $this->simpleProductBuilder = $simpleProductBuilder;
        $this->configurableProductBuilder = $configurableProductBuilder;
    }

    /**
     * Group items by parent/child relationships.
     *
     * @param array $items
     * @return array{simple: array, configurable: array, children: array<string, array>}
     */
    public function groupItems(array $items): array
    {
        $simple = [];
        $configurable = [];
        $childrenByParent = [];

        // Build parent SKU lookup from parent_netsuite_internal_id
        $parentSkuMap = []; // netsuite_internal_id => sku
        foreach ($items as $item) {
            $netsuiteId = $item['netsuite_internal_id'] ?? null;
            if ($netsuiteId) {
                $parentSkuMap[$netsuiteId] = $item['sku'];
            }
        }

        // Group children by parent SKU
        foreach ($items as $item) {
            $parentNetsuiteId = $item['parent_netsuite_internal_id'] ?? null;
            if ($parentNetsuiteId && isset($parentSkuMap[$parentNetsuiteId])) {
                $parentSku = $parentSkuMap[$parentNetsuiteId];
                if (!isset($childrenByParent[$parentSku])) {
                    $childrenByParent[$parentSku] = [];
                }
                $childrenByParent[$parentSku][] = $item;
            }
        }

        // Identify parent SKUs (those that have children)
        $parentSkus = array_keys($childrenByParent);

        // Categorize items
        foreach ($items as $item) {
            $sku = $item['sku'];
            $parentNetsuiteId = $item['parent_netsuite_internal_id'] ?? null;

            if ($parentNetsuiteId !== null && isset($parentSkuMap[$parentNetsuiteId])) {
                // Child variant with known parent - will be processed as part of configurable
                continue;
            }

            if (in_array($sku, $parentSkus, true)) {
                // Parent with children - configurable
                $configurable[] = $item;
            } else {
                // Standalone simple product
                $simple[] = $item;
            }
        }

        return [
            'simple' => $simple,
            'configurable' => $configurable,
            'children' => $childrenByParent,
        ];
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
        return $this->simpleProductBuilder->processSimpleProducts($items, $existingProducts, $updateExisting, $variantMappings, $optionMaps);
    }

    /**
     * Process configurable products (create parent + children).
     *
     * @param array $parentItems
     * @param array $childrenByParent
     * @param array $variantMappings
     * @param array $optionMaps
     * @param array $existingProducts
     * @param bool $updateExisting
     * @return array
     */
    public function processConfigurableProducts(
        array $parentItems,
        array $childrenByParent,
        array $variantMappings,
        array $optionMaps,
        array $existingProducts,
        bool $updateExisting
    ): array {
        return $this->configurableProductBuilder->processConfigurableProducts(
            $parentItems,
            $childrenByParent,
            $variantMappings,
            $optionMaps,
            $existingProducts,
            $updateExisting
        );
    }
}
