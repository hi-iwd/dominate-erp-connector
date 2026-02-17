<?php

namespace Dominate\ErpConnector\Cron;

use Dominate\ErpConnector\Model\Publisher;
use Dominate\ErpConnector\Model\QueueRepository;
use Psr\Log\LoggerInterface;

/**
 * Cron job to process sync queue.
 */
class QueueProcessor
{
    /**
     * @var QueueRepository
     */
    private QueueRepository $queueRepository;

    /**
     * @var Publisher
     */
    private Publisher $publisher;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * QueueProcessor constructor.
     *
     * @param QueueRepository $queueRepository
     * @param Publisher      $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        QueueRepository $queueRepository,
        Publisher      $publisher,
        LoggerInterface $logger
    ) {
        $this->queueRepository = $queueRepository;
        $this->publisher       = $publisher;
        $this->logger          = $logger;
    }

    /**
     * Execute cron job.
     *
     * @return void
     */
    public function execute(): void
    {
        $items = $this->queueRepository->getPendingItems(100);

        if (empty($items)) {
            return;
        }

        $this->logger->info('[Dominate_ErpConnector] Processing queue', [
            'count' => count($items),
        ]);

        foreach ($items as $item) {
            try {
                $success = $this->publisher->send(
                    $item->getEntityType(),
                    $item->getEvent(),
                    $item->getPayload()
                );

                if ($success) {
                    $this->queueRepository->deleteOnSuccess($item);
                } else {
                    $this->queueRepository->scheduleNextAttempt(
                        $item,
                        'HTTP request failed or returned error'
                    );
                }
            } catch (\Exception $e) {
                $this->queueRepository->scheduleNextAttempt(
                    $item,
                    $e->getMessage()
                );

                $this->logger->error('[Dominate_ErpConnector] Queue processing exception', [
                    'id'    => $item->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
