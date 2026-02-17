<?php

namespace Dominate\ErpConnector\Observer;

use Dominate\ErpConnector\Helper\AddressFormatter;
use Dominate\ErpConnector\Model\Publisher;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Observer for customer_save_after_data_object event.
 * Processes customers after they are saved.
 */
class CustomerSaveObserver implements ObserverInterface
{
    use AddressFormatter;

    /**
     * @var Publisher
     */
    private Publisher $publisher;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * CustomerSaveObserver constructor.
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
            $event = $observer->getEvent();
            /** @var CustomerInterface $customer */
            $customer = $event->getData('customer_data_object');

            if (!$customer || !$customer->getId()) {
                return;
            }

            // Detect if this is a new customer or an update
            $origCustomer = $event->getOrigCustomerDataObject();
            $eventType = ($origCustomer === null || !$origCustomer->getId()) ? 'create' : 'update';

            // For updates, only publish when meaningful fields actually changed.
            // This prevents duplicate "technical" updates that don't change customer data.
            if ($eventType === 'update' && $origCustomer instanceof CustomerInterface) {
                if (!$this->hasMeaningfulCustomerChanges($origCustomer, $customer)) {
                    $this->logger->info('[Dominate_ErpConnector] Customer update has no meaningful changes, skipping publish', [
                        'customer_id' => $customer->getId(),
                    ]);

                    return;
                }
            }

            $payload = $this->prepareCustomerPayload($customer);
            $this->publisher->publish('customers', (string) $customer->getId(), $eventType, $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[Dominate_ErpConnector] Customer observer exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Check if customer has meaningful data changes that should trigger sync.
     *
     * @param CustomerInterface $origCustomer
     * @param CustomerInterface $customer
     * @return bool
     */
    private function hasMeaningfulCustomerChanges(
        CustomerInterface $origCustomer,
        CustomerInterface $customer
    ): bool {
        foreach ([
            'getEmail',
            'getFirstname',
            'getLastname',
            'getMiddlename',
            'getPrefix',
            'getSuffix',
            'getDob',
            'getTaxvat',
            'getGender',
            'getGroupId',
            'getStoreId',
            'getWebsiteId',
            'getDefaultBilling',
            'getDefaultShipping'
        ] as $getter) {
            if ($origCustomer->$getter() !== $customer->$getter()) {
                return true;
            }
        }

        $normalizeCustomAttrs = static function (CustomerInterface $cust): array {
            $result = [];
            foreach ((array) $cust->getCustomAttributes() as $attr) {
                if (!$attr) {
                    continue;
                }
                $code  = $attr->getAttributeCode();
                $value = $attr->getValue();
                $result[$code] = $value;
            }
            ksort($result);
            return $result;
        };

        if ($normalizeCustomAttrs($origCustomer) !== $normalizeCustomAttrs($customer)) {
            return true;
        }

        return false;
    }
}

