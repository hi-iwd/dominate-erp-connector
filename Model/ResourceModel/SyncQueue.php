<?php

namespace Dominate\ErpConnector\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Sync queue resource model.
 */
class SyncQueue extends AbstractDb
{
    /**
     * Initialize resource model.
     */
    protected function _construct()
    {
        $this->_init('dominate_sync_queue', 'id');
    }
}

