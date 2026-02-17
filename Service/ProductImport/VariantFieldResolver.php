<?php

namespace Dominate\ErpConnector\Service\ProductImport;

/**
 * Resolves variant field labels from variant fields array by ERP field ID.
 */
class VariantFieldResolver
{
    /**
     * Find variant label by ERP field ID from variant fields array.
     *
     * @param array $variantFields
     * @param string $erpFieldId
     * @return string|null
     */
    public function findLabelByErpFieldId(array $variantFields, string $erpFieldId): ?string
    {
        foreach ($variantFields as $field) {
            if (is_array($field) && ($field['erp_field_id'] ?? null) === $erpFieldId && !empty($field['label'])) {
                return $field['label'];
            }
        }

        return null;
    }

    /**
     * Check if item has valid variant data for all required mappings.
     *
     * @param array $item
     * @param array $variantMappings
     * @return bool
     */
    public function hasValidVariantData(array $item, array $variantMappings): bool
    {
        // Ensure variant_fields is always an array (defensive programming)
        $variantFields = is_array($item['variant_fields'] ?? null) ? $item['variant_fields'] : [];

        foreach ($variantMappings as $mapping) {
            $erpFieldId = $mapping['erp_field_id'] ?? null;
            if ($erpFieldId === null) {
                continue; // Skip invalid mapping
            }

            $variantLabel = $this->findLabelByErpFieldId($variantFields, $erpFieldId);

            if ($variantLabel === null) {
                return false;
            }
        }

        return true;
    }
}
