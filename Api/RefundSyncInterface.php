<?php

namespace Dominate\ErpConnector\Api;

/**
 * Refund sync service interface.
 * Handles refund (credit memo) updates from ERP (NetSuite) to Magento 2.
 */
interface RefundSyncInterface
{
    /**
     * Sync refunds (credit memos) for orders.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param mixed  $refunds Array of refund updates with keys: order_increment_id, items, amount, reason, etc.
     * @return mixed[] Response array with 'Error' boolean and optional 'ErrorCode'
     */
    public function sync(
        string $api_key,
        int    $timestamp,
        string $signature,
        mixed  $refunds
    );
}

