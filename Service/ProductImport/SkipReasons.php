<?php

namespace Dominate\ErpConnector\Service\ProductImport;

/**
 * Centralized skip reason constants for product import.
 */
class SkipReasons
{
    /**
     * Product exists and update_existing is disabled.
     */
    public const PRODUCT_EXISTS_UPDATE_DISABLED = 'Product exists and update_existing is disabled';

    /**
     * Configurable requires at least 2 children.
     */
    public const CONFIGURABLE_INSUFFICIENT_CHILDREN = 'Configurable requires at least 2 children';

    /**
     * Missing required variant data.
     */
    public const MISSING_REQUIRED_VARIANT_DATA = 'Missing required variant data';

    /**
     * Configurable requires at least 2 valid children.
     */
    public const CONFIGURABLE_INSUFFICIENT_VALID_CHILDREN = 'Configurable requires at least 2 valid children';

    /**
     * No valid children to link.
     */
    public const NO_VALID_CHILDREN_TO_LINK = 'No valid children to link';

    /**
     * Price is required for new products.
     */
    public const PRICE_REQUIRED_FOR_CREATE = 'Price is required for new products';

    /**
     * Configurable has no applicable dimensions (no mapping differentiates children).
     */
    public const CONFIGURABLE_NO_APPLICABLE_DIMENSIONS = 'Configurable has no applicable dimensions';
}
