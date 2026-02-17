<?php

namespace Dominate\ErpConnector\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Connection checker helper.
 */
class ConnectionChecker extends AbstractHelper
{
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var array Cache for resolved base URLs by website ID
     */
    private array $baseUrlCache = [];

    /**
     * ConnectionChecker constructor.
     *
     * @param Context               $context
     * @param StoreManagerInterface $storeManager
     * @param Config                $config
     * @param ApiClient             $apiClient
     */
    public function __construct(
        Context               $context,
        StoreManagerInterface $storeManager,
        Config                $config,
        ApiClient             $apiClient
    ) {
        parent::__construct($context);
        $this->storeManager = $storeManager;
        $this->config       = $config;
        $this->apiClient    = $apiClient;
    }

    /**
     * Test the connection to the Dominate ERP Connector.
     *
     * @param int|null $websiteId
     * @return array
     */
    public function test(?int $websiteId = null): array
    {
        $apiKey    = $this->config->getApiKey($websiteId);
        $apiSecret = $this->config->getApiSecret($websiteId);

        if (!$apiKey || !$apiSecret) {
            return [
                'status'  => false,
                'message' => __('API credentials are empty.'),
                'code'    => 'empty_creds'
            ];
        }

        $websiteUrl = $this->resolveBaseUrl($websiteId);
        $response   = $this->apiClient->post('check-connection', [
            'platform'    => 'magento2',
            'website_url' => $websiteUrl,
        ], $websiteId);

        if ($this->apiClient->isSuccess($response)) {
            return [
                'status'  => true,
                'message' => __('Connected'),
                'code'    => 'ok'
            ];
        }

        $errorCode = $this->apiClient->getErrorCode($response);
        return [
            'status'  => false,
            'message' => $this->getErrorMessage($errorCode),
            'code'    => $errorCode
        ];
    }

    /**
     * Get user-friendly error message for error code.
     * @param string $errorCode
     * @return string
     */
    private function getErrorMessage(string $errorCode): string
    {
        $messages = [
            'missing_params'        => __('Required connection parameters are missing'),
            'wrong_api_credentials' => __('API credentials are incorrect'),
            'wrong_website_url'     => __('Website URL does not match'),
            'wrong_platform'        => __('Platform mismatch detected'),
            'invalid_signature'     => __('HMAC signature validation failed'),
            'stale_request'         => __('Request timestamp is too old'),
            'connect_error'         => __('Unable to reach the Dominate ERP Connector service'),
        ];

        return $messages[$errorCode] ?? __('Connection failed');
    }

    /**
     * Resolve the base URL for the given website ID.
     *
     * @param int|null $websiteId
     * @return string
     */
    private function resolveBaseUrl(?int $websiteId = null): string
    {
        $cacheKey = $websiteId ?? 'default';

        if (isset($this->baseUrlCache[$cacheKey])) {
            return $this->baseUrlCache[$cacheKey];
        }

        if ($websiteId !== null) {
            /** @var Store $store */
            foreach ($this->storeManager->getStores() as $store) {
                if ((int)$store->getWebsiteId() === $websiteId) {
                    $this->baseUrlCache[$cacheKey] = $store->getBaseUrl();
                    return $this->baseUrlCache[$cacheKey];
                }
            }
        }

        /** @var Store $store */
        foreach ($this->storeManager->getStores() as $store) {
            $this->baseUrlCache[$cacheKey] = $store->getBaseUrl();
            return $this->baseUrlCache[$cacheKey];
        }

        /** @var Store $fallbackStore */
        $fallbackStore = $this->storeManager->getStore();
        $this->baseUrlCache[$cacheKey] = $fallbackStore->getBaseUrl();

        return $this->baseUrlCache[$cacheKey];
    }
}
