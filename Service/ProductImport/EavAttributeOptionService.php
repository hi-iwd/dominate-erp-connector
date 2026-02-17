<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * EAV attribute option service.
 * Handles attribute validation and option management (fetch/create).
 */
class EavAttributeOptionService
{
    /**
     * @var AttributeRepositoryInterface
     */
    private AttributeRepositoryInterface $attributeRepository;

    /**
     * @var AttributeOptionManagementInterface
     */
    private AttributeOptionManagementInterface $optionManagement;

    /**
     * @var AttributeOptionInterfaceFactory
     */
    private AttributeOptionInterfaceFactory $optionFactory;

    /**
     * @var AttributeOptionLabelInterfaceFactory
     */
    private AttributeOptionLabelInterfaceFactory $optionLabelFactory;

    /**
     * @var EavConfig
     */
    private EavConfig $eavConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Entity type ID for catalog_product (cached per request).
     *
     * @var int|null
     */
    private ?int $productEntityTypeId = null;

    /**
     * EavAttributeOptionService constructor.
     *
     * @param AttributeRepositoryInterface $attributeRepository
     * @param AttributeOptionManagementInterface $optionManagement
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeOptionLabelInterfaceFactory $optionLabelFactory
     * @param EavConfig $eavConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        AttributeRepositoryInterface $attributeRepository,
        AttributeOptionManagementInterface $optionManagement,
        AttributeOptionInterfaceFactory $optionFactory,
        AttributeOptionLabelInterfaceFactory $optionLabelFactory,
        EavConfig $eavConfig,
        LoggerInterface $logger
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->optionManagement = $optionManagement;
        $this->optionFactory = $optionFactory;
        $this->optionLabelFactory = $optionLabelFactory;
        $this->eavConfig = $eavConfig;
        $this->logger = $logger;
    }

    /**
     * Validate that variant mapping attributes exist and are suitable for configurable products.
     *
     * @param array $variantMappings
     * @return array{has_errors: bool, error_message?: string}
     */
    public function validateAttributes(array $variantMappings): array
    {
        if (empty($variantMappings)) {
            return ['has_errors' => false];
        }

        foreach ($variantMappings as $mapping) {
            $attrCode = $mapping['store_attribute_code'];

            try {
                $attribute = $this->attributeRepository->get('catalog_product', $attrCode);

                // Check attribute is select-type (required for configurable)
                // Configurable products require single-select attributes (multiselect not supported)
                if ($attribute->getFrontendInput() !== 'select') {
                    return [
                        'has_errors' => true,
                        'error_message' => "Attribute '{$attrCode}' must be select type (not multiselect) for configurable products",
                    ];
                }

                // Check attribute is global scope (required for configurable super attributes)
                // getIsGlobal() returns: 0 = store view, 1 = website, 2 = global
                if (method_exists($attribute, 'getIsGlobal') && (int)$attribute->getIsGlobal() !== ScopedAttributeInterface::SCOPE_GLOBAL) {
                    return [
                        'has_errors' => true,
                        'error_message' => "Attribute '{$attrCode}' must be global scope for configurable products",
                    ];
                }
            } catch (NoSuchEntityException $e) {
                return [
                    'has_errors' => true,
                    'error_message' => "Attribute '{$attrCode}' does not exist",
                ];
            }
        }

        return ['has_errors' => false];
    }

    /**
     * Ensure attribute options exist (create missing ones).
     *
     * @param array $variantMappings
     * @param array $items
     * @return array<string, array> Attribute code => [attribute_id, options[label => option_id]]
     */
    public function ensureOptions(array $variantMappings, array $items): array
    {
        $optionMaps = [];

        foreach ($variantMappings as $mapping) {
            $attrCode = $mapping['store_attribute_code'];
            $erpFieldId = $mapping['erp_field_id'];

            // Collect unique option labels needed from items (normalized)
            $neededLabels = [];
            foreach ($items as $item) {
                $variantFields = $item['variant_fields'] ?? [];
                foreach ($variantFields as $field) {
                    if (($field['erp_field_id'] ?? null) === $erpFieldId && !empty($field['label'])) {
                        // Normalize label: trim whitespace and normalize case to avoid duplicates
                        $normalizedLabel = $this->normalizeLabel($field['label']);
                        if ($normalizedLabel !== '') {
                            $neededLabels[$normalizedLabel] = $field['label']; // Store original for lookup
                        }
                    }
                }
            }

            if (empty($neededLabels)) {
                continue;
            }

            $normalizedNeededLabels = array_keys($neededLabels);

            // Load attribute
            try {
                $attribute = $this->attributeRepository->get('catalog_product', $attrCode);
                $attributeId = (int)$attribute->getAttributeId();

                // Get existing options using AttributeOptionManagementInterface (normalized)
                $existingOptions = [];
                $normalizedToOriginal = []; // Map normalized -> original label for lookup
                $entityTypeId = $this->getProductEntityTypeId();
                $optionItems = $this->optionManagement->getItems($entityTypeId, $attrCode);

                foreach ($optionItems as $optionItem) {
                    $label = $optionItem->getLabel();
                    $value = $optionItem->getValue();
                    if ($label && $value) {
                        $normalizedLabel = $this->normalizeLabel($label);
                        $existingOptions[$normalizedLabel] = (int)$value;
                        $normalizedToOriginal[$normalizedLabel] = $label; // Preserve original
                    }
                }

                // Find missing labels (compare normalized)
                $missingNormalizedLabels = array_diff($normalizedNeededLabels, array_keys($existingOptions));

                // Create missing options (use original labels for creation)
                if (!empty($missingNormalizedLabels)) {
                    $missingOriginalLabels = [];
                    foreach ($missingNormalizedLabels as $normalized) {
                        $missingOriginalLabels[] = $neededLabels[$normalized];
                    }
                    $createdOptions = $this->createOptions($attribute, $missingOriginalLabels);
                    // Merge created options (keyed by normalized label)
                    foreach ($createdOptions as $originalLabel => $optionId) {
                        $normalized = $this->normalizeLabel($originalLabel);
                        $existingOptions[$normalized] = $optionId;
                        $normalizedToOriginal[$normalized] = $originalLabel;
                    }
                }

                // Build option map: normalized label -> option_id
                // Store with normalized keys to ensure consistent lookups (handles whitespace/case differences)
                // Also store original labels for reference if needed
                $optionMap = [];
                foreach ($existingOptions as $normalizedLabel => $optionId) {
                    $optionMap[$normalizedLabel] = $optionId;
                    // Also store original label as key for backward compatibility (if different from normalized)
                    $originalLabel = $normalizedToOriginal[$normalizedLabel] ?? $normalizedLabel;
                    if ($originalLabel !== $normalizedLabel && !isset($optionMap[$originalLabel])) {
                        $optionMap[$originalLabel] = $optionId;
                    }
                }

                $optionMaps[$attrCode] = [
                    'attribute_id' => $attributeId,
                    'options' => $optionMap,
                ];
            } catch (\Exception $e) {
                $this->logger->error('[Dominate_ErpConnector] Failed to ensure attribute options', [
                    'attribute_code' => $attrCode,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other attributes
            }
        }

        return $optionMaps;
    }

    /**
     * Create missing attribute options using AttributeOptionManagementInterface.
     *
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
     * @param array $labels
     * @return array<string, int> Label => option_id
     */
    private function createOptions($attribute, array $labels): array
    {
        $created = [];
        $entityTypeId = $this->getProductEntityTypeId();
        $attrCode = $attribute->getAttributeCode();

        foreach ($labels as $label) {
            try {
                // Create option using AttributeOptionManagementInterface
                /** @var AttributeOptionInterface $option */
                $option = $this->optionFactory->create();
                $optionLabel = $this->optionLabelFactory->create();
                $optionLabel->setStoreId(0); // Admin store
                $optionLabel->setLabel($label);
                $option->setLabel($label);
                $option->setStoreLabels([$optionLabel]);
                $option->setSortOrder(0);
                $option->setIsDefault(false);

                // Add option (entityTypeId must be integer)
                $optionId = $this->optionManagement->add($entityTypeId, $attrCode, $option);
                $created[$label] = (int)$optionId;
            } catch (\Exception $e) {
                $this->logger->error('[Dominate_ErpConnector] Failed to create attribute option', [
                    'attribute_code' => $attrCode,
                    'label' => $label,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other labels
            }
        }

        return $created;
    }

    /**
     * Get entity type ID for catalog_product.
     *
     * @return int
     * @throws LocalizedException
     */
    private function getProductEntityTypeId(): int
    {
        if ($this->productEntityTypeId === null) {
            $entityType = $this->eavConfig->getEntityType('catalog_product');
            $this->productEntityTypeId = (int)$entityType->getEntityTypeId();
        }

        return $this->productEntityTypeId;
    }

    /**
     * Normalize option label to avoid duplicates from whitespace/case differences.
     * Trims whitespace and normalizes to lowercase for comparison.
     *
     * @param string $label
     * @return string Normalized label
     */
    public function normalizeLabel(string $label): string
    {
        return self::normalizeLabelStatic($label);
    }

    /**
     * Static helper to normalize option labels (for use in other classes).
     *
     * @param string $label
     * @return string Normalized label
     */
    public static function normalizeLabelStatic(string $label): string
    {
        return mb_strtolower(trim($label));
    }
}
