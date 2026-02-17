<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Applies variant attributes to products.
 * Shared between SimpleProductBuilder and ConfigurableProductBuilder.
 */
class VariantAttributeApplier
{
    /**
     * @var VariantFieldResolver
     */
    private VariantFieldResolver $variantFieldResolver;

    /**
     * VariantAttributeApplier constructor.
     *
     * @param VariantFieldResolver $variantFieldResolver
     */
    public function __construct(VariantFieldResolver $variantFieldResolver)
    {
        $this->variantFieldResolver = $variantFieldResolver;
    }

    /**
     * Assign variant attributes to a product.
     *
     * @param ProductInterface $product
     * @param array $item
     * @param array $variantMappings
     * @param array $optionMaps
     * @return void
     */
    public function assignVariantAttributes(
        ProductInterface $product,
        array $item,
        array $variantMappings,
        array $optionMaps
    ): void {
        if (empty($variantMappings) || empty($optionMaps)) {
            return; // No variant mappings configured or no options available
        }

        $variantFields = $item['variant_fields'] ?? [];

        foreach ($variantMappings as $mapping) {
            $attrCode = $mapping['store_attribute_code'];
            $erpFieldId = $mapping['erp_field_id'] ?? null;

            if ($erpFieldId === null) {
                continue;
            }

            // Find matching variant field
            $variantLabel = $this->variantFieldResolver->findLabelByErpFieldId($variantFields, $erpFieldId);

            if ($variantLabel === null) {
                continue;
            }

            // Normalize label for lookup to match normalized option map keys
            $normalizedLabel = EavAttributeOptionService::normalizeLabelStatic($variantLabel);

            // Lookup with normalized label (optionMaps stores both normalized and original keys)
            if (!isset($optionMaps[$attrCode]['options'][$normalizedLabel])) {
                continue; // Skip silently - validation already happened upstream
            }

            $optionId = $optionMaps[$attrCode]['options'][$normalizedLabel];
            $product->setData($attrCode, $optionId);
        }
    }
}
