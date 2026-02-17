<?php

namespace Dominate\ErpConnector\Helper;

use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Address as CustomerAddressModel;
use Magento\Framework\Api\CustomAttributesDataInterface;
use Magento\Framework\Api\AttributeInterface;

/**
 * Helper trait for formatting addresses.
 */
trait AddressFormatter
{
    /**
     * Flatten order address object into simple array.
     *
     * @param OrderAddressInterface|null $address
     * @return array
     */
    private function flattenOrderAddress(?OrderAddressInterface $address): array
    {
        if (!$address) {
            return [];
        }

        $street      = $address->getStreet();
        $streetArray = is_array($street) ? $street : ($street ? [$street] : []);

        return [
            'firstname'  => $address->getFirstname(),
            'lastname'   => $address->getLastname(),
            'company'    => $address->getCompany(),
            'street'     => $streetArray,
            'city'       => $address->getCity(),
            'region'     => $address->getRegion(),
            'postcode'   => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone'  => $address->getTelephone(),
        ];
    }

    /**
     * Flatten customer address object into simple array.
     *
     * @param AddressInterface|null $address
     * @return array
     */
    private function flattenCustomerAddress(?AddressInterface $address): array
    {
        if (!$address) {
            return [];
        }

        $street      = $address->getStreet();
        $streetArray = is_array($street) ? $street : ($street ? [$street] : []);

        $data = [
            'entity_id'  => $address->getId(),
            'firstname'  => $address->getFirstname(),
            'lastname'   => $address->getLastname(),
            'company'    => $address->getCompany(),
            'street'     => $streetArray,
            'city'       => $address->getCity(),
            'region'     => $address->getRegion()->getRegion(),
            'postcode'   => $address->getPostcode(),
            'country_id' => $address->getCountryId(),
            'telephone'  => $address->getTelephone()
        ];

        // Include custom attributes
        $customAttrs = $this->extractCustomAttributes($address);
        if (!empty($customAttrs)) {
            $data = array_merge($data, $customAttrs);
        }

        return $data;
    }

    /**
     * Get meta information for payload.
     *
     * @return array
     */
    private function getMeta(): array
    {
        return [
            'connector'    => 'm2',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Extract custom attributes from an object that implements CustomAttributesDataInterface.
     * Returns an associative array where keys are attribute codes and values are attribute values.
     *
     * @param CustomAttributesDataInterface|null $entity
     * @return array<string, mixed>
     */
    private function extractCustomAttributes(?CustomAttributesDataInterface $entity): array
    {
        if (!$entity) {
            return [];
        }

        $customAttrs = $entity->getCustomAttributes();
        if (!$customAttrs || !is_array($customAttrs)) {
            return [];
        }

        $result = [];
        foreach ($customAttrs as $attr) {
            if (!$attr instanceof AttributeInterface) {
                continue;
            }

            $code = $attr->getAttributeCode();
            $value = $attr->getValue();

            // Skip null/empty values to keep payload clean
            if ($code && $value !== null && $value !== '') {
                $result[$code] = $value;
            }
        }

        return $result;
    }

    /**
     * Prepare customer payload for sync.
     *
     * @param CustomerInterface $customer
     * @param CustomerAddressModel|null $changedAddress Address that triggered the event (optional)
     * @return array
     */
    protected function prepareCustomerPayload(
        CustomerInterface     $customer,
        ?CustomerAddressModel $changedAddress = null
    ): array
    {
        $addresses = [];
        [$defaultBillingId, $defaultShippingId] = $this->resolveEffectiveDefaultIds($customer, $changedAddress);

        foreach ($customer->getAddresses() ?: [] as $address) {
            $data     = $this->flattenCustomerAddress($address);
            $entityId = (int)$address->getId();

            $data['default_billing']  = $entityId > 0 && $entityId === $defaultBillingId;
            $data['default_shipping'] = $entityId > 0 && $entityId === $defaultShippingId;

            $addresses[] = $data;
        }

        $customerData = [
            'entity_id'  => (int)$customer->getId(),
            'created_at' => $customer->getCreatedAt(),
            'updated_at' => $customer->getUpdatedAt(),
            'email'      => $customer->getEmail(),
            'firstname'  => $customer->getFirstname(),
            'lastname'   => $customer->getLastname(),
            'middlename' => $customer->getMiddlename(),
            'prefix'     => $customer->getPrefix(),
            'suffix'     => $customer->getSuffix(),
            'dob'        => $customer->getDob() ? substr($customer->getDob(), 0, 10) : null,
            'taxvat'     => $customer->getTaxvat(),
            'gender'     => $customer->getGender() !== null ? (int)$customer->getGender() : null,
            'group_id'   => (int)$customer->getGroupId(),
            'store_id'   => (int)$customer->getStoreId(),
            'website_id' => (int)$customer->getWebsiteId(),
        ];

        // Include custom attributes
        $customAttrs = $this->extractCustomAttributes($customer);
        if (!empty($customAttrs)) {
            $customerData = array_merge($customerData, $customAttrs);
        }

        return [
            'version'   => '1',
            'meta'      => $this->getMeta(),
            'customer'  => $customerData,
            'addresses' => $addresses,
        ];
    }

    /**
     * Resolve effective default billing/shipping IDs, taking into account the address
     * that triggered the event (if any).
     *
     * @param CustomerInterface $customer
     * @param CustomerAddressModel|null $changedAddress
     * @return int[] [billingId, shippingId]
     */
    private function resolveEffectiveDefaultIds(
        CustomerInterface     $customer,
        ?CustomerAddressModel $changedAddress
    ): array
    {
        $billingId  = (int)$customer->getDefaultBilling();
        $shippingId = (int)$customer->getDefaultShipping();

        if (!$changedAddress || !$changedAddress->getId()) {
            return [$billingId, $shippingId];
        }

        $changedId = (int)$changedAddress->getId();

        // Billing
        if ($changedAddress->getData('is_default_billing')) {
            $billingId = $changedId;
        } elseif ($billingId === $changedId) {
            $billingId = 0;
        }

        // Shipping
        if ($changedAddress->getData('is_default_shipping')) {
            $shippingId = $changedId;
        } elseif ($shippingId === $changedId) {
            $shippingId = 0;
        }

        return [$billingId, $shippingId];
    }
}

