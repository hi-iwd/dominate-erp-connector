<?php

namespace Dominate\ErpConnector\Observer;

use Dominate\ErpConnector\Model\Publisher;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Observer for customer_delete_after event.
 * Processes customer deletions and sends them to Laravel.
 */
class CustomerDeleteObserver implements ObserverInterface
{
    /**
     * @var Publisher
     */
    private Publisher $publisher;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * CustomerDeleteObserver constructor.
     *
     * @param Publisher      $publisher
     * @param LoggerInterface $logger
     */
    public function __construct(
        Publisher      $publisher,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->logger    = $logger;
    }

    /**
     * Execute observer.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            // Get customer data object from event
            /** @var CustomerInterface|null $customer */
            $customer = $observer->getData('data_object');

            if (!$customer || !$customer->getId()) {
                return;
            }

            // Prepare minimal payload for delete event
            $payload = $this->prepareDeletePayload($customer);

            // Publish delete event
            $this->publisher->publish('customers', (string) $customer->getId(), 'delete', $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[Dominate_ErpConnector] Customer delete observer exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Prepare payload for customer delete event.
     *
     * @param CustomerInterface $customer
     * @return array
     */
    private function prepareDeletePayload(CustomerInterface $customer): array
    {
        return [
            'version' => '1',
            'meta'    => [
                'connector'    => 'm2',
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            'customer' => [
                'entity_id' => (int) $customer->getId(),
                'email'     => $customer->getEmail(),
                'firstname' => $customer->getFirstname(),
                'lastname'  => $customer->getLastname(),
            ],
        ];
    }
}

