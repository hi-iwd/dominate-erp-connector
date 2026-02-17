<?php

namespace Dominate\ErpConnector\Model\ResourceModel\SyncQueue;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Dominate\ErpConnector\Model\SyncQueue as SyncQueueModel;
use Dominate\ErpConnector\Model\ResourceModel\SyncQueue as SyncQueueResource;

/**
 * Sync queue collection.
 */
class Collection extends AbstractCollection
{
    /**
     * Initialize collection.
     */
    protected function _construct()
    {
        $this->_init(SyncQueueModel::class, SyncQueueResource::class);
    }
}
