<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Api\ProductImportInterface;
use Dominate\ErpConnector\Helper\ApiAuthValidator;
use Dominate\ErpConnector\Service\ProductImport\EavAttributeOptionService;
use Dominate\ErpConnector\Service\ProductImport\ExistingProductIndexer;
use Dominate\ErpConnector\Service\ProductImport\ProductBuilder;
use Dominate\ErpConnector\Service\ProductImport\RequestNormalizer;
use Dominate\ErpConnector\Service\ProductImport\ResultAssembler;
use Psr\Log\LoggerInterface;

/**
 * Product import implementation.
 * Handles product import from ERP (NetSuite) to Magento 2.
 * Creates/updates simple and configurable products with variant attribute management.
 */
class ProductImport implements ProductImportInterface
{
    /**
     * @var ApiAuthValidator
     */
    private ApiAuthValidator $apiAuthValidator;

    /**
     * @var RequestNormalizer
     */
    private RequestNormalizer $requestNormalizer;

    /**
     * @var EavAttributeOptionService
     */
    private EavAttributeOptionService $attributeOptionService;

    /**
     * @var ExistingProductIndexer
     */
    private ExistingProductIndexer $productIndexer;

    /**
     * @var ProductBuilder
     */
    private ProductBuilder $productBuilder;

    /**
     * @var ResultAssembler
     */
    private ResultAssembler $resultAssembler;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ProductImport constructor.
     *
     * @param ApiAuthValidator $apiAuthValidator
     * @param RequestNormalizer $requestNormalizer
     * @param EavAttributeOptionService $attributeOptionService
     * @param ExistingProductIndexer $productIndexer
     * @param ProductBuilder $productBuilder
     * @param ResultAssembler $resultAssembler
     * @param LoggerInterface $logger
     */
    public function __construct(
        ApiAuthValidator $apiAuthValidator,
        RequestNormalizer $requestNormalizer,
        EavAttributeOptionService $attributeOptionService,
        ExistingProductIndexer $productIndexer,
        ProductBuilder $productBuilder,
        ResultAssembler $resultAssembler,
        LoggerInterface $logger
    ) {
        $this->apiAuthValidator = $apiAuthValidator;
        $this->requestNormalizer = $requestNormalizer;
        $this->attributeOptionService = $attributeOptionService;
        $this->productIndexer = $productIndexer;
        $this->productBuilder = $productBuilder;
        $this->resultAssembler = $resultAssembler;
        $this->logger = $logger;
    }

    /**
     * Import products from ERP into Magento 2.
     *
     * @param string $api_key
     * @param int $timestamp
     * @param string $signature
     * @param mixed $run_id Optional run ID for tracking
     * @param mixed $integration_id Optional integration ID for tracking
     * @param mixed $update_existing Whether to update existing products (0/1 or false/true)
     * @param mixed $variant_mappings Array of variant mappings
     * @param mixed $items Array of product items to import
     * @return mixed[]
     */
    public function import(
        string $api_key,
        int $timestamp,
        string $signature,
        mixed $run_id = null,
        mixed $integration_id = null,
        mixed $update_existing = false,
        mixed $variant_mappings = null,
        mixed $items = null
    ) {
        // Validate API credentials and HMAC signature
        $authResult = $this->apiAuthValidator->validate($api_key, $timestamp, $signature);
        if ($authResult['Error'] === true) {
            return $authResult;
        }

        // Normalize and validate input
        $normalized = $this->requestNormalizer->normalize($update_existing, $variant_mappings, $items);
        if ($normalized['error'] !== null) {
            return $normalized['error'];
        }

        $updateExisting = $normalized['update_existing'];
        $variantMappings = $normalized['variant_mappings'];
        $items = $normalized['items'];

        try {
            // Preload existing products by SKU
            $existingProducts = $this->productIndexer->index($items);

            // Validate attributes exist and are suitable for configurable
            $attributeValidation = $this->attributeOptionService->validateAttributes($variantMappings);
            if ($attributeValidation['has_errors']) {
                $results = [];
                foreach ($items as $item) {
                    $results[] = $this->resultAssembler->failed(
                        $item['sku'] ?? 'UNKNOWN',
                        $attributeValidation['error_message'],
                        'validation_error'
                    );
                }
                return [
                    'Error' => false,
                    'results' => $results,
                ];
            }

            // Ensure attribute options exist
            $optionMaps = $this->attributeOptionService->ensureOptions($variantMappings, $items);

            // Group items by parent/child relationships
            $grouped = $this->productBuilder->groupItems($items);

            // Process simple products (standalone)
            $results = $this->productBuilder->processSimpleProducts($grouped['simple'], $existingProducts, $updateExisting, $variantMappings, $optionMaps);

            // Process configurable products (parent + children)
            $configurableResults = $this->productBuilder->processConfigurableProducts(
                $grouped['configurable'],
                $grouped['children'],
                $variantMappings,
                $optionMaps,
                $existingProducts,
                $updateExisting
            );

            $results = array_merge($results, $configurableResults);

            // Collect warnings (skipped items) with size guard
            $warnings = [];
            $warningLimit = 200;
            foreach ($results as $result) {
                if (($result['status'] ?? '') === 'skipped' && isset($result['message'])) {
                    $warnings[] = "SKU {$result['sku']}: {$result['message']}";
                    if (count($warnings) >= $warningLimit) {
                        $warnings[] = 'Warning list truncated';
                        break;
                    }
                }
            }

            $response = [
                'Error' => false,
                'results' => $results,
            ];

            if (!empty($warnings)) {
                $response['warnings'] = $warnings;
            }

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('[Dominate_ErpConnector] Unexpected error during product import', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'Error' => true,
                'ErrorCode' => 'unexpected_error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
