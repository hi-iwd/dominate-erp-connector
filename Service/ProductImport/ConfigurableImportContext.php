<?php

namespace Dominate\ErpConnector\Service\ProductImport;

/**
 * DTO for a single configurable import run.
 * Holds run-level inputs to avoid long parameter lists across internal methods.
 */
class ConfigurableImportContext
{
    /**
     * Variant mappings configured for the integration (up to 3 dimensions).
     *
     * @var array
     */
    private array $variantMappings;

    /**
     * Option maps for mapped attributes (attribute_id + options label => option_id map).
     *
     * @var array
     */
    private array $optionMaps;

    /**
     * Existing products index by SKU (used to avoid repository lookups).
     *
     * @var array
     */
    private array $existingProducts;

    /**
     * Whether existing products should be updated.
     *
     * @var bool
     */
    private bool $updateExisting;

    /**
     * Default attribute set ID used for new products.
     *
     * @var int
     */
    private int $defaultAttributeSetId;

    /**
     * Default tax class ID used for new products.
     *
     * @var int
     */
    private int $defaultTaxClassId;

    /**
     * ConfigurableImportContext constructor.
     *
     * @param array $variantMappings
     * @param array $optionMaps
     * @param array $existingProducts
     * @param bool $updateExisting
     * @param int $defaultAttributeSetId
     * @param int $defaultTaxClassId
     */
    public function __construct(
        array $variantMappings,
        array $optionMaps,
        array $existingProducts,
        bool $updateExisting,
        int $defaultAttributeSetId,
        int $defaultTaxClassId
    ) {
        $this->variantMappings = $variantMappings;
        $this->optionMaps = $optionMaps;
        $this->existingProducts = $existingProducts;
        $this->updateExisting = $updateExisting;
        $this->defaultAttributeSetId = $defaultAttributeSetId;
        $this->defaultTaxClassId = $defaultTaxClassId;
    }

    /**
     * Get configured variant mappings.
     *
     * @return array
     */
    public function getVariantMappings(): array
    {
        return $this->variantMappings;
    }

    /**
     * Get option maps for mapped attributes.
     *
     * @return array
     */
    public function getOptionMaps(): array
    {
        return $this->optionMaps;
    }

    /**
     * Get existing product index by SKU.
     *
     * @return array
     */
    public function getExistingProducts(): array
    {
        return $this->existingProducts;
    }

    /**
     * Check whether existing products should be updated.
     *
     * @return bool
     */
    public function shouldUpdateExisting(): bool
    {
        return $this->updateExisting;
    }

    /**
     * Get default attribute set ID for new products.
     *
     * @return int
     */
    public function getDefaultAttributeSetId(): int
    {
        return $this->defaultAttributeSetId;
    }

    /**
     * Get default tax class ID for new products.
     *
     * @return int
     */
    public function getDefaultTaxClassId(): int
    {
        return $this->defaultTaxClassId;
    }
}

