<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Dominate\ErpConnector\Service\ProductImport\SkipReasons;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\Data\ProductExtensionInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as OptionsFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Api\AttributeManagementInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Configurable product builder service.
 * Handles creation and updates of configurable products and their child variants.
 */
class ConfigurableProductBuilder
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var ProductInterfaceFactory
     */
    private ProductInterfaceFactory $productFactory;

    /**
     * @var ProductExtensionInterfaceFactory
     */
    private ProductExtensionInterfaceFactory $productExtensionFactory;

    /**
     * @var OptionsFactory
     */
    private OptionsFactory $optionsFactory;

    /**
     * @var AttributeRepositoryInterface
     */
    private AttributeRepositoryInterface $attributeRepository;

    /**
     * @var AttributeManagementInterface
     */
    private AttributeManagementInterface $attributeManagement;

    /**
     * @var ProductDefaultsResolver
     */
    private ProductDefaultsResolver $defaultsResolver;

    /**
     * @var SimpleProductBuilder
     */
    private SimpleProductBuilder $simpleProductBuilder;

    /**
     * @var ResultAssembler
     */
    private ResultAssembler $resultAssembler;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var VariantFieldResolver
     */
    private VariantFieldResolver $variantFieldResolver;

    /**
     * @var ConfigurableProductUpsertService
     */
    private ConfigurableProductUpsertService $upsertService;

    /**
     * @var VariantAttributeApplier
     */
    private VariantAttributeApplier $variantAttributeApplier;

    /**
     * @var ProductCommonFieldsApplier
     */
    private ProductCommonFieldsApplier $commonFieldsApplier;

    /**
     * Attribute codes cache by attribute set ID.
     *
     * @var array<int, array<string, bool>>
     */
    private array $attributeSetAttributeCodes = [];

    /**
     * ConfigurableProductBuilder constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ProductInterfaceFactory $productFactory
     * @param ProductExtensionInterfaceFactory $productExtensionFactory
     * @param OptionsFactory $optionsFactory
     * @param AttributeRepositoryInterface $attributeRepository
     * @param AttributeManagementInterface $attributeManagement
     * @param ProductDefaultsResolver $defaultsResolver
     * @param SimpleProductBuilder $simpleProductBuilder
     * @param ResultAssembler $resultAssembler
     * @param LoggerInterface $logger
     * @param VariantFieldResolver $variantFieldResolver
     * @param ConfigurableProductUpsertService $upsertService
     * @param VariantAttributeApplier $variantAttributeApplier
     * @param ProductCommonFieldsApplier $commonFieldsApplier
     */
    public function __construct(
        ProductRepositoryInterface       $productRepository,
        ProductInterfaceFactory          $productFactory,
        ProductExtensionInterfaceFactory $productExtensionFactory,
        OptionsFactory                   $optionsFactory,
        AttributeRepositoryInterface     $attributeRepository,
        AttributeManagementInterface     $attributeManagement,
        ProductDefaultsResolver          $defaultsResolver,
        SimpleProductBuilder             $simpleProductBuilder,
        ResultAssembler                  $resultAssembler,
        LoggerInterface                  $logger,
        VariantFieldResolver             $variantFieldResolver,
        ConfigurableProductUpsertService $upsertService,
        VariantAttributeApplier          $variantAttributeApplier,
        ProductCommonFieldsApplier       $commonFieldsApplier
    )
    {
        $this->productRepository       = $productRepository;
        $this->productFactory          = $productFactory;
        $this->productExtensionFactory = $productExtensionFactory;
        $this->optionsFactory          = $optionsFactory;
        $this->attributeRepository     = $attributeRepository;
        $this->attributeManagement     = $attributeManagement;
        $this->defaultsResolver        = $defaultsResolver;
        $this->simpleProductBuilder    = $simpleProductBuilder;
        $this->resultAssembler         = $resultAssembler;
        $this->logger                  = $logger;
        $this->variantFieldResolver    = $variantFieldResolver;
        $this->upsertService           = $upsertService;
        $this->variantAttributeApplier = $variantAttributeApplier;
        $this->commonFieldsApplier     = $commonFieldsApplier;

        // Wire up the callback for creating configurable products
        $this->upsertService->setCreateConfigurableProductCallback(
            [$this, 'createConfigurableProduct']
        );
    }

    /**
     * Process configurable products (create parent + children).
     *
     * @param array $parentItems
     * @param array $childrenByParent
     * @param array $variantMappings
     * @param array $optionMaps
     * @param array $existingProducts
     * @param bool $updateExisting
     * @return array
     * @throws LocalizedException
     */
    public function processConfigurableProducts(
        array $parentItems,
        array $childrenByParent,
        array $variantMappings,
        array $optionMaps,
        array $existingProducts,
        bool  $updateExisting
    ): array
    {
        $results = [];
        $context = new ConfigurableImportContext(
            $variantMappings,
            $optionMaps,
            $existingProducts,
            $updateExisting,
            $this->defaultsResolver->getDefaultAttributeSetId(),
            $this->defaultsResolver->getDefaultTaxClassId()
        );

        foreach ($parentItems as $parentItem) {
            $parentResults = $this->processConfigurableParent(
                $parentItem,
                isset($parentItem['sku']) ? ($childrenByParent[$parentItem['sku']] ?? []) : [],
                $context
            );
            $results       = array_merge($results, $parentResults);
        }

        return $results;
    }

    /**
     * Process a single configurable parent product and its children.
     *
     * @param array $parentItem
     * @param array $children
     * @param ConfigurableImportContext $context
     * @return array
     */
    private function processConfigurableParent(
        array                     $parentItem,
        array                     $children,
        ConfigurableImportContext $context
    ): array
    {
        $results         = [];
        $parentSku       = $parentItem['sku'] ?? null;
        $variantMappings = $context->getVariantMappings();

        // Log configurable processing start for parent
        $this->logger->info('[Dominate_ErpConnector] Processing configurable parent', [
            'sku'                    => $parentSku,
            'children_count'         => count($children),
            'variant_mappings_count' => count($variantMappings),
        ]);

        // Guard against empty parent SKU
        if ($parentSku === null || trim((string)$parentSku) === '') {
            $this->logger->error('[Dominate_ErpConnector] Configurable parent SKU is missing', [
                'parent_item' => $parentItem,
            ]);
            return [$this->resultAssembler->failed('UNKNOWN', 'Parent SKU is missing')];
        }

        // Validate children count
        if (count($children) < 2) {
            $results[] = $this->resultAssembler->skipped($parentSku, SkipReasons::CONFIGURABLE_INSUFFICIENT_CHILDREN);
            return array_merge($results, $this->processChildrenAsSimples(
                $children,
                $context
            ));
        }

        // Determine which variant mappings are applicable for this parent
        $applicableMappings = $this->determineApplicableMappings($children, $variantMappings);

        if (empty($applicableMappings)) {
            $this->logger->info('[Dominate_ErpConnector] No applicable dimensions for configurable', [
                'sku'            => $parentSku,
                'children_count' => count($children),
            ]);
            $results[] = $this->resultAssembler->skipped($parentSku, SkipReasons::CONFIGURABLE_NO_APPLICABLE_DIMENSIONS);
            $results   = array_merge($results, $this->processChildrenAsSimples(
                $children,
                $context
            ));
            return $results;
        }

        // Filter children with valid variant data for applicable mappings only
        [$validChildren, $invalidChildren] = $this->filterValidChildren($children, $applicableMappings);

        if (count($validChildren) < 2) {
            $results[] = $this->resultAssembler->skipped($parentSku, SkipReasons::CONFIGURABLE_INSUFFICIENT_VALID_CHILDREN);
            return array_merge($results, $this->processChildrenAsSimples(
                $children,
                $context
            ));
        }

        // Parent will be created - add skipped results for invalid children
        foreach ($invalidChildren as $invalidChild) {
            $results[] = $this->resultAssembler->skipped($invalidChild['sku'], SkipReasons::MISSING_REQUIRED_VARIANT_DATA);
        }

        try {
            $this->logger->info('[Dominate_ErpConnector] Valid children for configurable', [
                'sku'                      => $parentSku,
                'valid_children_count'     => count($validChildren),
                'applicable_mapping_codes' => array_values(array_map(static function ($mapping) {
                    return $mapping['store_attribute_code'] ?? null;
                }, $applicableMappings)),
            ]);

            // Process children and collect IDs
            [$childProductIds, $childResults] = $this->processChildrenForConfigurable(
                $validChildren,
                $context
            );
            $results = array_merge($results, $childResults);

            // Process parent configurable
            $parentResults = $this->processParentConfigurable(
                $parentItem,
                $parentSku,
                $childProductIds,
                $applicableMappings,
                $validChildren,
                $context
            );
            $results       = array_merge($results, $parentResults);
        } catch (\Exception $e) {
            $this->logger->error('[Dominate_ErpConnector] Configurable product import failed', [
                'sku'         => $parentSku,
                'error_class' => get_class($e),
                'error'       => $e->getMessage(),
            ]);
            $results[] = $this->resultAssembler->failed($parentSku, $e->getMessage());
        }

        return $results;
    }

    /**
     * Filter children into valid and invalid based on applicable mappings.
     *
     * @param array $children
     * @param array $applicableMappings
     * @return array{0: array, 1: array} [validChildren, invalidChildren]
     */
    private function filterValidChildren(array $children, array $applicableMappings): array
    {
        $validChildren   = [];
        $invalidChildren = [];

        foreach ($children as $child) {
            if ($this->variantFieldResolver->hasValidVariantData($child, $applicableMappings)) {
                $validChildren[] = $child;
            } else {
                $invalidChildren[] = $child;
            }
        }

        return [$validChildren, $invalidChildren];
    }

    /**
     * Process children for configurable product and return child IDs and results.
     *
     * @param array $validChildren
     * @param ConfigurableImportContext $context
     * @return array{0: array, 1: array} [childProductIds, results]
     */
    private function processChildrenForConfigurable(
        array                     $validChildren,
        ConfigurableImportContext $context
    ): array
    {
        $childProductIds  = [];
        $results          = [];
        $existingProducts = $context->getExistingProducts();
        $updateExisting   = $context->shouldUpdateExisting();

        // Note: Even if update_existing=false, we still link existing children to new configurable parents
        foreach ($validChildren as $childItem) {
            $childSku    = $childItem['sku'];
            $childExists = isset($existingProducts[$childSku]);

            try {
                if ($childExists && !$updateExisting) {
                    // Child exists and update disabled - skip update but still link to parent
                    try {
                        $childProduct = $this->productRepository->get($childSku);
                        $childId      = (int)$childProduct->getId();
                        if ($childId > 0) {
                            $childProductIds[] = $childId;
                            $results[]         = $this->resultAssembler->skipped($childSku, SkipReasons::PRODUCT_EXISTS_UPDATE_DISABLED);
                        } else {
                            $results[] = $this->resultAssembler->failed($childSku, 'Existing child product has invalid ID');
                        }
                    } catch (NoSuchEntityException $e) {
                        // Product doesn't actually exist - treat as new
                        $childExists = false;
                    }
                    if ($childExists) {
                        continue; // Already handled above
                    }
                }

                [$childId, $resultEntry] = $this->upsertApplyVariantsSaveChild($childItem, $childExists, $context);
                if ($childId !== null) {
                    $childProductIds[] = $childId;
                }
                $results[] = $resultEntry;
            } catch (\Exception $e) {
                $this->logger->error('[Dominate_ErpConnector] Child product import failed', [
                    'sku'   => $childSku,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $this->resultAssembler->failed($childSku, $e->getMessage());
            }
        }

        return [$childProductIds, $results];
    }

    /**
     * Process parent configurable product (upsert, validate, set attributes, link children).
     *
     * @param array $parentItem
     * @param string $parentSku
     * @param array $childProductIds
     * @param array $applicableMappings
     * @param array $validChildren
     * @param ConfigurableImportContext $context
     * @return array
     */
    private function processParentConfigurable(
        array                     $parentItem,
        string                    $parentSku,
        array                     $childProductIds,
        array                     $applicableMappings,
        array                     $validChildren,
        ConfigurableImportContext $context
    ): array
    {
        $existingProducts      = $context->getExistingProducts();
        $updateExisting        = $context->shouldUpdateExisting();
        $defaultAttributeSetId = $context->getDefaultAttributeSetId();
        $defaultTaxClassId     = $context->getDefaultTaxClassId();
        $optionMaps            = $context->getOptionMaps();

        // Filter out invalid child IDs
        $childProductIds = array_values(array_filter($childProductIds));
        if (empty($childProductIds)) {
            return [$this->resultAssembler->skipped($parentSku, SkipReasons::NO_VALID_CHILDREN_TO_LINK)];
        }

        $parentExists = isset($existingProducts[$parentSku]);
        if ($parentExists && !$updateExisting) {
            return [$this->resultAssembler->skipped($parentSku, SkipReasons::PRODUCT_EXISTS_UPDATE_DISABLED)];
        }

        $result = $this->upsertService->getOrCreateParentProduct(
            $parentItem,
            $parentExists,
            $defaultAttributeSetId,
            $defaultTaxClassId
        );

        if ($result['error'] !== null) {
            return [$this->resultAssembler->failed(
                $parentSku,
                $result['error'],
                'missing_required_field'
            )];
        }

        $parentProduct = $result['product'];
        $parentExists  = $result['exists'];

        // Validate attribute set contains applicable configurable attributes
        $attributeSetId    = $parentProduct ? (int)$parentProduct->getAttributeSetId() : $defaultAttributeSetId;
        $missingAttributes = $this->getMissingConfigurableAttributes($applicableMappings, $attributeSetId);
        if (!empty($missingAttributes)) {
            $this->logger->warning('[Dominate_ErpConnector] Configurable attributes missing from attribute set', [
                'sku'                => $parentSku,
                'attribute_set_id'   => $attributeSetId,
                'missing_attributes' => $missingAttributes,
            ]);
            return [$this->resultAssembler->failed(
                $parentSku,
                'Attributes not in attribute set ID ' . $attributeSetId . ': ' . implode(', ', $missingAttributes),
                'validation_error'
            )];
        }

        // Save new parent before attaching configurable data
        if (!$parentExists) {
            $parentProduct = $this->productRepository->save($parentProduct);
            $parentId      = (int)$parentProduct->getId();
            if ($parentId === 0) {
                $this->logger->error('[Dominate_ErpConnector] Parent product saved without ID', [
                    'sku' => $parentSku,
                ]);
                return [$this->resultAssembler->failed($parentSku, 'Parent product ID missing after save')];
            }
            $this->logger->info('[Dominate_ErpConnector] Created configurable parent shell', [
                'sku'       => $parentSku,
                'parent_id' => $parentId,
            ]);
        }

        // Set configurable attributes and link children
        $this->logger->info('[Dominate_ErpConnector] Setting configurable attributes', [
            'sku'                      => $parentSku,
            'child_ids_count'          => count($childProductIds),
            'applicable_mapping_codes' => array_values(array_map(static function ($mapping) {
                return $mapping['store_attribute_code'] ?? null;
            }, $applicableMappings)),
        ]);
        $this->setConfigurableAttributes($parentProduct, $applicableMappings, $optionMaps, $childProductIds, $validChildren);

        // Save parent with configurable attributes and links
        $parentProduct = $this->productRepository->save($parentProduct);

        return [$this->resultAssembler->success($parentProduct, $parentExists ? 'updated' : 'created')];
    }

    /**
     * Get attribute codes for an attribute set (cached).
     *
     * @param int $attributeSetId
     * @return array<string, bool>
     */
    private function getAttributeCodesForSet(int $attributeSetId): array
    {
        if (isset($this->attributeSetAttributeCodes[$attributeSetId])) {
            return $this->attributeSetAttributeCodes[$attributeSetId];
        }

        $attributes = $this->attributeManagement->getAttributes('catalog_product', $attributeSetId);
        $codes      = [];
        foreach ($attributes as $attribute) {
            $code = $attribute->getAttributeCode();
            if ($code) {
                $codes[$code] = true;
            }
        }

        $this->attributeSetAttributeCodes[$attributeSetId] = $codes;
        return $codes;
    }

    /**
     * Determine which mapped configurable attributes are missing from the attribute set.
     *
     * @param array $variantMappings
     * @param int $attributeSetId
     * @return array
     */
    private function getMissingConfigurableAttributes(array $variantMappings, int $attributeSetId): array
    {
        $attributeCodes = $this->getAttributeCodesForSet($attributeSetId);
        $missing        = [];
        foreach ($variantMappings as $mapping) {
            $code = $mapping['store_attribute_code'] ?? null;
            if ($code && !isset($attributeCodes[$code])) {
                $missing[] = $code;
            }
        }
        return $missing;
    }

    /**
     * Create a configurable product.
     *
     * @param array $item
     * @param int $attributeSetId
     * @param int $taxClassId
     * @return ProductInterface
     */
    public function createConfigurableProduct(array $item, int $attributeSetId, int $taxClassId): ProductInterface
    {
        /** @var ProductInterface $product */
        $product = $this->productFactory->create();

        $product->setSku($item['sku']);
        $product->setTypeId(Configurable::TYPE_CODE);
        $product->setAttributeSetId($attributeSetId);
        $product->setStatus(Status::STATUS_DISABLED);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setData('tax_class_id', $taxClassId);

        // Apply common fields (name, website, weight, price, description, stock)
        $this->commonFieldsApplier->applyCommonFields($product, $item, 'configurable product');

        return $product;
    }

    /**
     * Set configurable attributes on parent product and link children.
     * Only includes option values actually used by children (optimization).
     *
     * @param ProductInterface $product
     * @param array $variantMappings
     * @param array $optionMaps
     * @param array $childProductIds
     * @param array $children Array of child items to determine which options are actually used
     * @return void
     */
    private function setConfigurableAttributes(ProductInterface $product, array $variantMappings, array $optionMaps, array $childProductIds, array $children = []): void
    {
        // Collect option IDs actually used by children (optimization: don't include all options)
        $usedOptionIdsByAttribute = [];
        foreach ($children as $child) {
            $variantFields = $child['variant_fields'] ?? [];

            foreach ($variantMappings as $mapping) {
                $attrCode   = $mapping['store_attribute_code'];
                $erpFieldId = $mapping['erp_field_id'];
                $label      = $this->variantFieldResolver->findLabelByErpFieldId($variantFields, $erpFieldId);

                if ($label === null) {
                    continue;
                }

                // Normalize label for lookup to match normalized option map keys
                $normalizedLabel = EavAttributeOptionService::normalizeLabelStatic($label);

                // Lookup with normalized label (optionMaps stores both normalized and original keys)
                if (isset($optionMaps[$attrCode]['options'][$normalizedLabel])) {
                    $optionId = $optionMaps[$attrCode]['options'][$normalizedLabel];
                    if (!isset($usedOptionIdsByAttribute[$attrCode])) {
                        $usedOptionIdsByAttribute[$attrCode] = [];
                    }
                    $usedOptionIdsByAttribute[$attrCode][$optionId] = true;
                }
            }
        }

        // Build configurable attributes data structure
        $configurableAttributesData = [];
        $position                   = 0;

        foreach ($variantMappings as $mapping) {
            $attrCode = $mapping['store_attribute_code'];
            if (!isset($optionMaps[$attrCode]['attribute_id'])) {
                continue;
            }

            $attributeId   = $optionMaps[$attrCode]['attribute_id'];
            $allOptions    = $optionMaps[$attrCode]['options'] ?? [];
            $usedOptionIds = $usedOptionIdsByAttribute[$attrCode] ?? [];

            // Build values array - only include options actually used by children
            // Deduplicate by value_index (option_id) since optionMaps may contain both normalized and original keys
            $values        = [];
            $seenOptionIds = []; // Track which option_ids we've already added
            foreach ($allOptions as $label => $optionId) {
                // Only include if this option is used by at least one child and we haven't seen this option_id yet
                if (isset($usedOptionIds[$optionId]) && !isset($seenOptionIds[$optionId])) {
                    $values[]                 = [
                        'label'        => $label,
                        'attribute_id' => $attributeId,
                        'value_index'  => $optionId,
                    ];
                    $seenOptionIds[$optionId] = true;
                }
            }

            // Skip attribute if no values (shouldn't happen, but be safe)
            if (empty($values)) {
                continue;
            }

            try {
                $attribute                    = $this->attributeRepository->get('catalog_product', $attrCode);
                $storeLabel                   = method_exists($attribute, 'getStoreLabel') ? $attribute->getStoreLabel() : null;
                $configurableAttributesData[] = [
                    'attribute_id' => $attributeId,
                    'code'         => $attrCode,
                    'label'        => $storeLabel ?: $attrCode,
                    'position'     => $position++,
                    'values'       => $values,
                ];
            } catch (\Exception $e) {
                $this->logger->error('[Dominate_ErpConnector] Failed to load attribute for configurable', [
                    'attribute_code' => $attrCode,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        if (!empty($configurableAttributesData)) {
            // Initialize extension attributes if null
            $extensionAttributes = $product->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->productExtensionFactory->create();
                $product->setExtensionAttributes($extensionAttributes);
            }

            // Use OptionsFactory to create configurable options
            $configurableOptions = $this->optionsFactory->create($configurableAttributesData);
            $extensionAttributes->setConfigurableProductOptions($configurableOptions);
            $extensionAttributes->setConfigurableProductLinks($childProductIds);
        }
    }

    /**
     * Process children as simple products when parent configurable cannot be created.
     *
     * @param array $children
     * @param ConfigurableImportContext $context
     * @return array Results for processed children
     */
    private function processChildrenAsSimples(
        array                     $children,
        ConfigurableImportContext $context
    ): array
    {
        $results          = [];
        $existingProducts = $context->getExistingProducts();
        $updateExisting   = $context->shouldUpdateExisting();

        foreach ($children as $child) {
            $childSku    = $child['sku'];
            $childExists = isset($existingProducts[$childSku]);

            if ($childExists && !$updateExisting) {
                $results[] = $this->resultAssembler->skipped($childSku, SkipReasons::PRODUCT_EXISTS_UPDATE_DISABLED);
                continue;
            }

            try {
                [, $resultEntry] = $this->upsertApplyVariantsSaveChild($child, $childExists, $context);
                $results[] = $resultEntry;
            } catch (\Exception $e) {
                $this->logger->error('[Dominate_ErpConnector] Child product import failed', [
                    'sku'   => $childSku,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $this->resultAssembler->failed($childSku, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Upsert child product, apply variant attributes, save, and validate ID.
     * Shared logic between processChildrenForConfigurable and processChildrenAsSimples.
     *
     * @param array $childItem
     * @param bool $childExists
     * @param ConfigurableImportContext $context
     * @return array{0: int|null, 1: array} [childId (null on error), resultEntry]
     */
    private function upsertApplyVariantsSaveChild(
        array                     $childItem,
        bool                      $childExists,
        ConfigurableImportContext $context
    ): array
    {
        $childSku              = $childItem['sku'];
        $defaultAttributeSetId = $context->getDefaultAttributeSetId();
        $defaultTaxClassId     = $context->getDefaultTaxClassId();
        $variantMappings       = $context->getVariantMappings();
        $optionMaps            = $context->getOptionMaps();

        $result = $this->upsertService->getOrCreateChildProduct(
            $childItem,
            $childExists,
            $defaultAttributeSetId,
            $defaultTaxClassId
        );

        if ($result['error'] !== null) {
            return [
                null,
                $this->resultAssembler->failed(
                    $childSku,
                    $result['error'],
                    'missing_required_field'
                )
            ];
        }

        $childProduct = $result['product'];
        $childExists  = $result['exists'];
        $this->variantAttributeApplier->assignVariantAttributes($childProduct, $childItem, $variantMappings, $optionMaps);

        // Persist child and ensure we have a valid ID
        $childProduct = $this->productRepository->save($childProduct);
        $childId      = (int)$childProduct->getId();
        if ($childId === 0) {
            $this->logger->error('[Dominate_ErpConnector] Child product saved without ID', [
                'sku' => $childSku,
            ]);
            return [
                null,
                $this->resultAssembler->failed($childSku, 'Child product ID missing after save')
            ];
        }

        return [
            $childId,
            $this->resultAssembler->success($childProduct, $childExists ? 'updated' : 'created')
        ];
    }

    /**
     * Determine which variant mappings are applicable for this parent.
     * A mapping is applicable only if it differentiates children (>=2 distinct labels).
     *
     * @param array $children
     * @param array $variantMappings
     * @return array Subset of variantMappings that have >=2 distinct values
     */
    private function determineApplicableMappings(array $children, array $variantMappings): array
    {
        $applicable = [];

        foreach ($variantMappings as $mapping) {
            $erpFieldId = $mapping['erp_field_id'] ?? null;
            if ($erpFieldId === null) {
                continue; // Skip invalid mapping
            }

            $distinctLabels = [];

            foreach ($children as $child) {
                // Ensure variant_fields is always an array (defensive programming)
                $variantFields = is_array($child['variant_fields'] ?? null) ? $child['variant_fields'] : [];
                $label         = $this->variantFieldResolver->findLabelByErpFieldId(
                    $variantFields,
                    $erpFieldId
                );

                if ($label !== null && trim((string)$label) !== '') {
                    $distinctLabels[$label] = true;
                }
            }

            // Mapping is applicable if it has >= 2 distinct values
            if (count($distinctLabels) >= 2) {
                $applicable[] = $mapping;
            }
        }

        return $applicable;
    }
}
