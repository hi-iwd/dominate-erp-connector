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
 * Observer for customer_address_save_after event.
 * Processes customer updates when addresses are saved.
 */
class CustomerAddressSaveObserver implements ObserverInterface
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
     * @param Publisher $publisher
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     * @param CustomerRegistry $customerRegistry
     */
    public function __construct(
        Publisher                   $publisher,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface             $logger,
        CustomerRegistry            $customerRegistry
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
            $address = $observer->getCustomerAddress();

            if (!$address || !$address->getId()) {
                return;
            }

            // Only publish when the address itself has meaningful changes.
            // This prevents extra webhooks when only account info (name, email, etc.) changes.
            if (!$this->hasMeaningfulAddressChanges($address)) {
                return;
            }

            $customerId = $address->getCustomerId();
            if (!$customerId) {
                return;
            }

            // Load full customer data (for core fields like email, name, etc.)
            // IMPORTANT: clear registry cache so we get the updated data (new address + defaults)
            $this->customerRegistry->remove((int)$customerId);
            $customer = $this->customerRepository->getById($customerId);

            // Let prepareCustomerPayload load ALL addresses from the fresh customer.
            // This ensures defaults and the new address are all present.
            $payload = $this->prepareCustomerPayload($customer, $address);

            $this->publisher->publish('customers', (string)$customerId, 'update', $payload);
        } catch (\Throwable $e) {
            $this->logger->error('[Dominate_ErpConnector] Customer address observer exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    /**
     * Check if address has meaningful data changes that should trigger sync.
     *
     * @param Address $address
     * @return bool
     */
    private function hasMeaningfulAddressChanges(Address $address): bool
    {
        // Check address field changes
        foreach ([
            'firstname',
            'lastname',
            'company',
            'street',
            'city',
            'region',
            'postcode',
            'country_id',
            'telephone',
        ] as $field) {
            if ($address->dataHasChangedFor($field)) {
                return true;
            }
        }

        // Check if default billing/shipping flags changed,
        if ($address->dataHasChangedFor('is_default_billing') || $address->dataHasChangedFor('is_default_shipping')) {
            return true;
        }

        $customAttrs = $address->getCustomAttributes();
        if ($customAttrs) {
            foreach ($customAttrs as $attr) {
                if (!$attr) {
                    continue;
                }
                $code  = $attr->getAttributeCode();
                $value = $attr->getValue();
                $orig  = $address->getOrigData($code);

                if ($orig !== $value) {
                    return true;
                }
            }
        }

        return false;
    }
}

