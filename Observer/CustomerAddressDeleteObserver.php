<?php

namespace Dominate\ErpConnector\Observer;

use Dominate\ErpConnector\Helper\AddressFormatter;
use Dominate\ErpConnector\Model\Publisher;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Address;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Customer\Model\CustomerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Observer for customer_address_delete_after event.
 * Processes customer updates when addresses are deleted.
 */
class CustomerAddressDeleteObserver implements ObserverInterface
{
    use AddressFormatter;

    /**
     * @var Publisher
     */
    private Publisher $publisher;

    /**
     * @var CustomerRepositoryInterface
     */
    private CustomerRepositoryInterface $customerRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /** @var CustomerRegistry */
    private CustomerRegistry $customerRegistry;

    /**
     * CustomerAddressDeleteObserver constructor.
     *
     * @param Publisher                  $publisher
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface            $logger
     * @param CustomerRegistry           $customerRegistry
     */
    public function __construct(
        Publisher                  $publisher,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface            $logger,
        CustomerRegistry           $customerRegistry
    ) {
        $this->publisher          = $publisher;
        $this->customerRepository = $customerRepository;
        $this->logger             = $logger;
        $this->customerRegistry   = $customerRegistry;
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
            /** @var Address $address */
            $address = $observer->getEvent()->getData('customer_address');

            if (!$address || !$address->getId()) {
                return;
            }

            $customerId = $address->getCustomerId();
            if (!$customerId) {
                return;
            }

            // Load full customer data (for core fields like email, name, etc.)
            // IMPORTANT: clear registry cache so we get the updated data (address already deleted)
            $this->customerRegistry->remove((int)$customerId);
            $customer = $this->customerRepository->getById($customerId);

            // Let prepareCustomerPayload load ALL remaining addresses from the fresh customer.
            $payload = $this->prepareCustomerPayload($customer);

            $this->publisher->publish('customers', (string)$customerId, 'update', $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[Dominate_ErpConnector] Customer address delete observer exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}

