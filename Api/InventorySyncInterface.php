<?php

namespace Dominate\ErpConnector\Api;

/**
 * Inventory sync service interface.
 * Handles inventory and price updates from ERP (NetSuite) to Magento 2.
 */
interface InventorySyncInterface
{
    /**
     * Sync inventory and/or price for products.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $products Array of product updates with keys: sku, qty (optional), price (optional)
     * @return mixed[] Response array with 'Error' boolean and optional 'ErrorCode'
     */
    public function sync(
        string $api_key,
        int    $timestamp,
        string $signature,
        mixed  $products
    );
}

