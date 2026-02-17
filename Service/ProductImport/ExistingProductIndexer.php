<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Existing product indexer service.
 * Preloads existing products by SKU for efficient lookup.
 */
class ExistingProductIndexer
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ExistingProductIndexer constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * Preload existing products by SKU for efficient lookup.
     * Uses getList() with search criteria for better performance.
     *
     * @param array $items
     * @return array<string, array> SKU => [id, type_id, attribute_set_id, status]
     */
    public function index(array $items): array
    {
        $skus = array_unique(array_filter(array_column($items, 'sku')));
        if (empty($skus)) {
            return [];
        }

        $lookup = [];

        try {
            // Use getList() with search criteria for batch loading
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('sku', $skus, 'in')
                ->setPageSize(count($skus))
                ->create();

            $searchResults = $this->productRepository->getList($searchCriteria);

            foreach ($searchResults->getItems() as $product) {
                $sku = $product->getSku();
                $lookup[$sku] = [
                    'id' => (int)$product->getId(),
                    'type_id' => $product->getTypeId(),
                    'attribute_set_id' => (int)$product->getAttributeSetId(),
                    'status' => (int)$product->getStatus(),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('[Dominate_ErpConnector] Failed to preload existing products', [
                'error' => $e->getMessage(),
            ]);
            // Fallback to individual lookups if batch fails
            foreach ($skus as $sku) {
                try {
                    $product = $this->productRepository->get($sku);
                    $lookup[$sku] = [
                        'id' => (int)$product->getId(),
                        'type_id' => $product->getTypeId(),
                        'attribute_set_id' => (int)$product->getAttributeSetId(),
                        'status' => (int)$product->getStatus(),
                    ];
                } catch (NoSuchEntityException $e) {
                    // Product doesn't exist - skip
                }
            }
        }

        return $lookup;
    }
}
