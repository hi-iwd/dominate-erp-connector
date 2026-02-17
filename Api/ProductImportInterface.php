<?php

namespace Dominate\ErpConnector\Api;

/**
 * Product import service interface.
 * Handles product import from ERP (NetSuite) to Magento 2.
 * Creates/updates simple and configurable products with variant attribute management.
 */
interface ProductImportInterface
{
    /**
     * Import products from ERP into Magento 2.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $run_id Optional run ID for tracking
     * @param mixed  $integration_id Optional integration ID for tracking
     * @param mixed  $update_existing Whether to update existing products (0/1 or false/true)
     * @param mixed  $variant_mappings Array of variant mappings
     * @param mixed  $items Array of product items to import
     * @return mixed[] Response array with 'Error' boolean, 'results' array, and optional 'warnings'
     */
    public function import(
        string $api_key,
        int    $timestamp,
        string $signature,
        mixed  $run_id = null,
        mixed  $integration_id = null,
        mixed  $update_existing = false,
        mixed  $variant_mappings = null,
        mixed  $items = null
    );
}
