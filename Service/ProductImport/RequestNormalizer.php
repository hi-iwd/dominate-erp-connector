<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Psr\Log\LoggerInterface;

/**
 * Request normalizer for product import.
 * Handles input normalization, validation, and error formatting.
 */
class RequestNormalizer
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ResultAssembler
     */
    private ResultAssembler $resultAssembler;

    /**
     * RequestNormalizer constructor.
     *
     * @param LoggerInterface $logger
     * @param ResultAssembler $resultAssembler
     */
    public function __construct(LoggerInterface $logger, ResultAssembler $resultAssembler)
    {
        $this->logger = $logger;
        $this->resultAssembler = $resultAssembler;
    }

    /**
     * Normalize and validate request input.
     *
     * @param mixed $update_existing
     * @param mixed $variant_mappings
     * @param mixed $items
     * @return array{update_existing: bool, variant_mappings: array, items: array, error: array|null}
     */
    public function normalize(mixed $update_existing, mixed $variant_mappings, mixed $items): array
    {
        // Normalize input
        $updateExisting = (bool)(is_numeric($update_existing) ? (int)$update_existing : $update_existing);
        $variantMappings = is_array($variant_mappings) ? $variant_mappings : [];
        $items = is_array($items) ? $items : [];

        // Validate required fields
        if (empty($items)) {
            $this->logger->warning('[Dominate_ErpConnector] Product import failed: invalid_items');
            return [
                'update_existing' => $updateExisting,
                'variant_mappings' => $variantMappings,
                'items' => $items,
                'error' => [
                    'Error' => false,
                    'results' => $this->markAllItemsFailed($items, 'No items provided'),
                ],
            ];
        }

        // Validate variant mappings (max 3, required fields)
        if (count($variantMappings) > 3) {
            $this->logger->warning('[Dominate_ErpConnector] Product import failed: too_many_variant_mappings');
            return [
                'update_existing' => $updateExisting,
                'variant_mappings' => $variantMappings,
                'items' => $items,
                'error' => [
                    'Error' => false,
                    'results' => $this->markAllItemsFailed($items, 'Maximum 3 variant dimensions allowed'),
                ],
            ];
        }

        foreach ($variantMappings as $mapping) {
            if (empty($mapping['store_attribute_code']) || empty($mapping['erp_field_id'])) {
                $this->logger->warning('[Dominate_ErpConnector] Product import failed: invalid_variant_mapping');
                return [
                    'update_existing' => $updateExisting,
                    'variant_mappings' => $variantMappings,
                    'items' => $items,
                    'error' => [
                        'Error' => false,
                        'results' => $this->markAllItemsFailed($items, 'Variant mappings must have both store_attribute_code and erp_field_id'),
                    ],
                ];
            }
        }

        // Validate each item structure
        $validationErrors = [];
        foreach ($items as $index => $item) {
            // Validate SKU presence
            if (empty($item['sku']) || !is_string($item['sku'])) {
                $validationErrors[] = "Item at index {$index}: SKU is required and must be a string";
                continue;
            }

            // Validate variant_fields (must be in canonical list format)
            if (isset($item['variant_fields'])) {
                if (!is_array($item['variant_fields'])) {
                    $validationErrors[] = "Item {$item['sku']}: variant_fields must be an array";
                } else {
                    $this->validateCanonicalVariantFields($item['variant_fields'], $item['sku'], $validationErrors);
                }
            }

            // Validate numeric types
            if (isset($item['qty']) && !is_numeric($item['qty'])) {
                $validationErrors[] = "Item {$item['sku']}: qty must be numeric";
            }
            if (isset($item['price']) && !is_numeric($item['price'])) {
                $validationErrors[] = "Item {$item['sku']}: price must be numeric";
            }
            if (isset($item['weight']) && !is_numeric($item['weight'])) {
                $validationErrors[] = "Item {$item['sku']}: weight must be numeric";
            }
        }

        if (!empty($validationErrors)) {
            $errorMessage = 'Validation failed: ' . implode('; ', $validationErrors);
            $this->logger->warning('[Dominate_ErpConnector] Product import validation failed', [
                'errors' => $validationErrors,
            ]);
            return [
                'update_existing' => $updateExisting,
                'variant_mappings' => $variantMappings,
                'items' => $items,
                'error' => [
                    'Error' => false,
                    'results' => $this->markAllItemsFailed($items, $errorMessage),
                ],
            ];
        }

        return [
            'update_existing' => $updateExisting,
            'variant_mappings' => $variantMappings,
            'items' => $items,
            'error' => null,
        ];
    }

    /**
     * Validate variant_fields structure (strict canonical format enforcement).
     * Expected format: [{"erp_field_id": "custitem1180", "id": "2", "label": "Black"}, ...]
     * Multi-select values appear as multiple objects with the same erp_field_id.
     *
     * @param array $variantFields
     * @param string $sku
     * @param array $validationErrors
     * @return void
     */
    private function validateCanonicalVariantFields(array $variantFields, string $sku, array &$validationErrors): void
    {
        if (empty($variantFields)) {
            return; // Empty array is valid
        }

        // Validate each element is an object with required keys
        foreach ($variantFields as $vfIndex => $vf) {
            if (!is_array($vf)) {
                $validationErrors[] = "Item {$sku}: variant_fields[{$vfIndex}] must be an array";
                continue;
            }

            // Strict validation: erp_field_id is required
            if (empty($vf['erp_field_id']) || !is_string($vf['erp_field_id'])) {
                $validationErrors[] = "Item {$sku}: variant_fields[{$vfIndex}].erp_field_id is required and must be a string";
            }

            // id is required
            if (!isset($vf['id']) || (!is_string($vf['id']) && !is_numeric($vf['id']))) {
                $validationErrors[] = "Item {$sku}: variant_fields[{$vfIndex}].id is required and must be a string or number";
            }

            // label is optional but must be string or null if present
            if (array_key_exists('label', $vf) && $vf['label'] !== null && !is_string($vf['label'])) {
                $validationErrors[] = "Item {$sku}: variant_fields[{$vfIndex}].label must be a string or null";
            }
        }
    }

    /**
     * Mark all items as failed with error message using ResultAssembler.
     *
     * @param array $items
     * @param string $errorMessage
     * @return array
     */
    private function markAllItemsFailed(array $items, string $errorMessage): array
    {
        $results = [];
        foreach ($items as $item) {
            $sku = $item['sku'] ?? 'UNKNOWN';
            $results[] = $this->resultAssembler->failed($sku, $errorMessage, 'validation_error');
        }
        return $results;
    }
}
