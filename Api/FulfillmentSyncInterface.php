<?php

namespace Dominate\ErpConnector\Api;

/**
 * Fulfillment sync service interface.
 * Handles fulfillment (shipment) updates from ERP (NetSuite) to Magento 2.
 */
interface FulfillmentSyncInterface
{
    /**
     * Sync fulfillments (shipments) for orders.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $shipments Array of shipment updates with keys: order_increment_id, items, tracking_numbers, carrier_code, etc.
     * @return mixed[] Response array with 'Error' boolean and optional 'ErrorCode'
     */
    public function sync(
        string $api_key,
        int    $timestamp,
        string $signature,
        mixed  $shipments
    );
}

