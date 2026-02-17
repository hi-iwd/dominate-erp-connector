<?php

namespace Dominate\ErpConnector\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Sync queue model.
 */
class SyncQueue extends AbstractModel
{
    /**
     * Initialize resource model.
     */
    protected function _construct()
    {
        $this->_init(\Dominate\ErpConnector\Model\ResourceModel\SyncQueue::class);
    }

    /**
     * Get entity type.
     *
     * @return string
     */
    public function getEntityType(): string
    {
        return $this->getData('entity_type');
    }

    /**
     * Get entity ID.
     *
     * @return string
     */
    public function getEntityId(): string
    {
        return $this->getData('entity_id');
    }

    /**
     * Get event type.
     *
     * @return string
     */
    public function getEvent(): string
    {
        return $this->getData('event');
    }

    /**
     * Get payload as array.
     *
     * @return array
     */
    public function getPayload(): array
    {
        $payload = $this->getData('payload');
        return $payload ? json_decode($payload, true) : [];
    }

    /**
     * Set payload from array.
     *
     * @param array $payload
     * @return $this
     */
    public function setPayload(array $payload): self
    {
        return $this->setData('payload', json_encode($payload));
    }

    /**
     * Get attempts count.
     *
     * @return int
     */
    public function getAttempts(): int
    {
        return (int) $this->getData('attempts');
    }

    /**
     * Increment attempts.
     *
     * @return $this
     */
    public function incrementAttempts(): self
    {
        return $this->setData('attempts', $this->getAttempts() + 1);
    }
}

