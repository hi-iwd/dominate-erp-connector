<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Helper\ApiClient;
use Dominate\ErpConnector\Helper\Config;
use Dominate\ErpConnector\Model\SyncQueueFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Publisher for sending sync events to Laravel.
 */
class Publisher
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var SyncQueueFactory
     */
    private SyncQueueFactory $syncQueueFactory;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var DateTime
     */
    private DateTime $dateTime;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Publisher constructor.
     *
     * @param Config           $config
     * @param SyncQueueFactory $syncQueueFactory
     * @param ApiClient        $apiClient
     * @param DateTime         $dateTime
     * @param LoggerInterface  $logger
     */
    public function __construct(
        Config           $config,
        SyncQueueFactory $syncQueueFactory,
        ApiClient        $apiClient,
        DateTime         $dateTime,
        LoggerInterface  $logger
    ) {
        $this->config           = $config;
        $this->syncQueueFactory = $syncQueueFactory;
        $this->apiClient        = $apiClient;
        $this->dateTime         = $dateTime;
        $this->logger           = $logger;
    }

    /**
     * Publish event immediately or queue if failed.
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $event
     * @param array  $payload
     * @return bool True if sent immediately, false if queued
     */
    public function publish(string $entityType, string $entityId, string $event, array $payload): bool
    {
        // If extension is disabled, do nothing
        if (!$this->config->isEnabled()) {
            return false;
        }

        // TODO: Implement SyncContext service to prevent outbound webhooks when products
        // are updated from ERP. This will be needed when we add outbound product observers.

        $apiKey    = $this->config->getApiKey();
        $apiSecret = $this->config->getApiSecret();

        // If credentials not configured, queue the item
        if (!$apiKey || !$apiSecret) {
            $this->queue($entityType, $entityId, $event, $payload);
            return false;
        }

        // Try to send immediately
        if ($this->send($entityType, $event, $payload)) {
            return true;
        }

        // If send failed, queue for retry
        $this->queue($entityType, $entityId, $event, $payload);
        return false;
    }

    /**
     * Send event to Laravel endpoint.
     *
     * @param string $entityType
     * @param string $event
     * @param array  $payload
     * @return bool
     */
    public function send(string $entityType, string $event, array $payload): bool
    {
        $response = $this->apiClient->post($entityType, [
            'event'   => $event,
            'payload' => $payload,
        ]);

        if ($this->apiClient->isSuccess($response)) {
            return true;
        }

        $this->logger->warning('[Dominate_ErpConnector] Publisher send failed', [
            'entity_type' => $entityType,
            'status'      => $response['status'] ?? 0,
            'error_code'  => $this->apiClient->getErrorCode($response),
        ]);

        return false;
    }

    /**
     * Queue event for later processing.
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $event
     * @param array  $payload
     * @return void
     */
    private function queue(string $entityType, string $entityId, string $event, array $payload): void
    {
        $queueItem = $this->syncQueueFactory->create();
        $queueItem->setData('entity_type', $entityType)
            ->setData('entity_id', $entityId)
            ->setData('event', $event)
            ->setPayload($payload)
            ->setData('attempts', 0)
            ->setData('next_attempt_at', $this->dateTime->gmtDate())
            ->save();

        $this->logger->debug('[Dominate_ErpConnector] Event queued', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'event'       => $event,
        ]);
    }
}

