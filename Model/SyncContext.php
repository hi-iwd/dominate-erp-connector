<?php

namespace Dominate\ErpConnector\Model;

/**
 * Sync context to mark ERP-initiated operations inside a single HTTP request.
 * Used to prevent outbound webhook loops for shipments and refunds.
 */
class SyncContext
{
    /**
     * @var bool
     */
    private bool $erpFulfillmentSync = false;

    /**
     * @var bool
     */
    private bool $erpRefundSync = false;

    /**
     * Mark start of ERP-initiated fulfillment sync.
     *
     * @return void
     */
    public function startErpFulfillmentSync(): void
    {
        $this->erpFulfillmentSync = true;
    }

    /**
     * Mark end of ERP-initiated fulfillment sync.
     *
     * @return void
     */
    public function endErpFulfillmentSync(): void
    {
        $this->erpFulfillmentSync = false;
    }

    /**
     * Check if current request handles ERP-initiated fulfillment sync.
     *
     * @return bool
     */
    public function isErpFulfillmentSync(): bool
    {
        return $this->erpFulfillmentSync;
    }

    /**
     * Mark start of ERP-initiated refund sync.
     *
     * @return void
     */
    public function startErpRefundSync(): void
    {
        $this->erpRefundSync = true;
    }

    /**
     * Mark end of ERP-initiated refund sync.
     *
     * @return void
     */
    public function endErpRefundSync(): void
    {
        $this->erpRefundSync = false;
    }

    /**
     * Check if current request handles ERP-initiated refund sync.
     *
     * @return bool
     */
    public function isErpRefundSync(): bool
    {
        return $this->erpRefundSync;
    }
}

