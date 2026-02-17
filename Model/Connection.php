<?php

namespace Dominate\ErpConnector\Model;

use Dominate\ErpConnector\Api\ConnectionInterface;
use Dominate\ErpConnector\Helper\ApiAuthValidator;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Connection check implementation.
 */
class Connection implements ConnectionInterface
{
    /**
     * @var ApiAuthValidator
     */
    private ApiAuthValidator $apiAuthValidator;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Connection constructor.
     *
     * @param ApiAuthValidator      $apiAuthValidator
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface       $logger
     */
    public function __construct(
        ApiAuthValidator      $apiAuthValidator,
        StoreManagerInterface $storeManager,
        LoggerInterface       $logger
    ) {
        $this->apiAuthValidator = $apiAuthValidator;
        $this->storeManager     = $storeManager;
        $this->logger           = $logger;
    }

    /**
     * Check connection from Laravel to Magento 2.
     *
     * @param string $api_key
     * @param int    $timestamp
     * @param string $signature
     * @param string $platform
     * @param string $website_url
     * @return mixed[]
     */
    public function check(
        string $api_key,
        int    $timestamp,
        string $signature,
        string $platform,
        string $website_url
    ) {
        // Validate API credentials and HMAC signature
        $authResult = $this->apiAuthValidator->validate($api_key, $timestamp, $signature);
        if ($authResult['Error'] === true) {
            return $authResult;
        }

        // Verify platform matches
        if ($platform !== 'magento2') {
            $this->logger->warning('[Dominate_ErpConnector] Connection check failed: wrong_platform');
            return ['Error' => true, 'ErrorCode' => 'wrong_platform'];
        }

        // 6. Verify website URL matches (normalize both for comparison)
        $storeBaseUrl        = $this->getStoreBaseUrl();
        $normalizedStoreUrl  = $this->normalizeUrl($storeBaseUrl);
        $normalizedRequestUrl = $this->normalizeUrl($website_url);

        if ($normalizedStoreUrl !== $normalizedRequestUrl) {
            $this->logger->warning('[Dominate_ErpConnector] Connection check failed: wrong_website_url');
            return ['Error' => true, 'ErrorCode' => 'wrong_website_url'];
        }

        return ['Error' => false];
    }

    /**
     * Get store base URL.
     *
     * @return string
     */
    private function getStoreBaseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl();
    }

    /**
     * Normalize URL for comparison (remove protocol and trailing slash).
     *
     * @param string $url
     * @return string
     */
    private function normalizeUrl(string $url): string
    {
        $url = strtolower($url);
        $url = rtrim($url, '/');

        // Remove http:// or https:// protocol
        return preg_replace('#^https?://#', '', $url);
    }
}

