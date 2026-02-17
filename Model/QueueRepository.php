<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Model\ResourceModel\SyncQueue\CollectionFactory;
use Dominate\ErpConnector\Model\SyncQueueFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Queue repository for managing sync queue items.
 */
class QueueRepository
{
    /**
     * Back-off schedule in minutes: [attempt_number => minutes]
     */
    private const BACKOFF_SCHEDULE = [
        1 => 0,    // Immediate retry
        2 => 5,    // 5 minutes
        3 => 15,   // 15 minutes
        4 => 60,   // 1 hour
        5 => 360,  // 6 hours
        6 => 1440, // 24 hours
    ];

    /**
     * Maximum attempts before marking as failed.
     */
    private const MAX_ATTEMPTS = 6;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var SyncQueueFactory
     */
    private SyncQueueFactory $syncQueueFactory;

    /**
     * @var DateTime
     */
    private DateTime $dateTime;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * QueueRepository constructor.
     *
     * @param CollectionFactory $collectionFactory
     * @param SyncQueueFactory  $syncQueueFactory
     * @param DateTime          $dateTime
     * @param LoggerInterface   $logger
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        SyncQueueFactory  $syncQueueFactory,
        DateTime          $dateTime,
        LoggerInterface   $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->syncQueueFactory  = $syncQueueFactory;
        $this->dateTime          = $dateTime;
        $this->logger            = $logger;
    }

    /**
     * Get pending items ready for processing.
     *
     * @param int $limit
     * @return SyncQueue[]
     */
    public function getPendingItems(int $limit = 100): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('attempts', ['lt' => self::MAX_ATTEMPTS])
            ->addFieldToFilter('next_attempt_at', ['lte' => new \Zend_Db_Expr('NOW()')])
            ->setOrder('created_at', 'ASC')
            ->setPageSize($limit);

        // Lock rows to prevent concurrent processing
        $collection->getSelect()->forUpdate(true);

        return $collection->getItems();
    }

    /**
     * Delete item on successful sync.
     *
     * @param SyncQueue $item
     * @return void
     */
    public function deleteOnSuccess(SyncQueue $item): void
    {
        $item->delete();
    }

    /**
     * Mark item as failed after max attempts.
     *
     * @param SyncQueue $item
     * @param string    $errorMessage
     * @return void
     */
    public function markAsFailed(SyncQueue $item, string $errorMessage): void
    {
        $item->setData('error_message', $errorMessage)
            ->setData('next_attempt_at', null)
            ->save();

        $this->logger->warning('[Dominate_ErpConnector] Queue item marked as failed', [
            'id'           => $item->getId(),
            'entity_type'  => $item->getEntityType(),
            'entity_id'    => $item->getEntityId(),
            'attempts'     => $item->getAttempts(),
            'error'        => $errorMessage,
        ]);
    }

    /**
     * Schedule next attempt with exponential back-off.
     *
     * @param SyncQueue $item
     * @param string    $errorMessage
     * @return void
     */
    public function scheduleNextAttempt(SyncQueue $item, string $errorMessage): void
    {
        $attempts = $item->getAttempts() + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->markAsFailed($item, $errorMessage);
            return;
        }

        $minutes = self::BACKOFF_SCHEDULE[$attempts] ?? self::BACKOFF_SCHEDULE[self::MAX_ATTEMPTS];
        $now        = $this->dateTime->gmtTimestamp();
        $nextAttempt = $this->dateTime->gmtDate(null, $now + ($minutes * 60));

        $item->incrementAttempts()
            ->setData('last_attempt_at', $this->dateTime->gmtDate())
            ->setData('next_attempt_at', $nextAttempt)
            ->setData('error_message', $errorMessage)
            ->save();

        $this->logger->info('[Dominate_ErpConnector] Queue item scheduled for retry', [
            'id'            => $item->getId(),
            'entity_type'   => $item->getEntityType(),
            'entity_id'     => $item->getEntityId(),
            'attempts'      => $attempts,
            'next_attempt'  => $nextAttempt,
        ]);
    }

    /**
     * Calculate next attempt timestamp for given attempt number.
     *
     * @param int $attemptNumber
     * @return string
     */
    public function getNextAttemptTime(int $attemptNumber): string
    {
        $minutes = self::BACKOFF_SCHEDULE[$attemptNumber] ?? self::BACKOFF_SCHEDULE[self::MAX_ATTEMPTS];
        $now     = $this->dateTime->gmtTimestamp();

        return $this->dateTime->gmtDate(null, $now + ($minutes * 60));
    }
}

