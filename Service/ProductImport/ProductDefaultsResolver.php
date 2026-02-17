<?php

namespace Dominate\ErpConnector\Service\ProductImport;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Product defaults resolver service.
 * Resolves Magento defaults (attribute set, tax class, website).
 */
class ProductDefaultsResolver
{
    /**
     * @var EavConfig
     */
    private EavConfig $eavConfig;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * Default attribute set ID (cached per request).
     *
     * @var int|null
     */
    private ?int $defaultAttributeSetId = null;

    /**
     * Default product tax class ID (cached per request).
     *
     * @var int|null
     */
    private ?int $defaultTaxClassId = null;

    /**
     * Default website ID (cached per request).
     *
     * @var int|null
     */
    private ?int $defaultWebsiteId = null;

    /**
     * ProductDefaultsResolver constructor.
     *
     * @param EavConfig $eavConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        EavConfig $eavConfig,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->eavConfig = $eavConfig;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    /**
     * Get default attribute set ID for catalog_product entity type.
     *
     * @return int
     * @throws LocalizedException
     */
    public function getDefaultAttributeSetId(): int
    {
        if ($this->defaultAttributeSetId === null) {
            $entityType = $this->eavConfig->getEntityType('catalog_product');
            $this->defaultAttributeSetId = (int)$entityType->getDefaultAttributeSetId();
        }

        return $this->defaultAttributeSetId;
    }

    /**
     * Get default product tax class ID from configuration.
     *
     * @return int
     */
    public function getDefaultTaxClassId(): int
    {
        if ($this->defaultTaxClassId === null) {
            $this->defaultTaxClassId = (int)$this->scopeConfig->getValue(
                'tax/classes/default_product_tax_class',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
        }

        return $this->defaultTaxClassId;
    }

    /**
     * Get default website ID.
     * Returns the first non-admin website to ensure products are assigned to a real website
     * even when API is called in admin scope (/rest/all/...).
     *
     * @return int
     */
    public function getDefaultWebsiteId(): int
    {
        if ($this->defaultWebsiteId === null) {
            try {
                $websites = $this->storeManager->getWebsites();
                foreach ($websites as $website) {
                    $websiteId = (int)$website->getId();
                    // Skip admin website (ID 0) - use first real website
                    if ($websiteId > 0) {
                        $this->defaultWebsiteId = $websiteId;
                        break;
                    }
                }
                // Fallback to website ID 1 if no non-admin website found
                if ($this->defaultWebsiteId === null) {
                    $this->defaultWebsiteId = 1;
                }
            } catch (\Exception $e) {
                // Fallback to website ID 1 if store manager fails
                $this->defaultWebsiteId = 1;
            }
        }

        return $this->defaultWebsiteId;
    }
}
