<?php

namespace Dominate\ErpConnector\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Psr\Log\LoggerInterface;

/**
 * API authentication validator helper.
 * Centralizes HMAC signature verification logic for reuse across API endpoints.
 */
class ApiAuthValidator extends AbstractHelper
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Hmac
     */
    private Hmac $hmac;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ApiAuthValidator constructor.
     *
     * @param Context $context
     * @param Config  $config
     * @param Hmac    $hmac
     */
    public function __construct(
        Context $context,
        Config  $config,
        Hmac    $hmac
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->hmac   = $hmac;
        $this->logger = $context->getLogger();
    }

    /**
     * Validate API key, timestamp, and HMAC signature.
     * Returns error response array on failure, or ['Error' => false] on success.
     *
     * @param string $apiKey
     * @param int    $timestamp
     * @param string $signature
     * @return array Response array with 'Error' boolean and optional 'ErrorCode'
     */
    public function validate(string $apiKey, int $timestamp, string $signature): array
    {
        // 1. Verify API key matches
        $storedApiKey = $this->config->getApiKey();
        if (!$storedApiKey || $storedApiKey !== $apiKey) {
            $this->logger->warning('[Dominate_ErpConnector] Auth validation failed: wrong_api_credentials');
            return ['Error' => true, 'ErrorCode' => 'wrong_api_credentials'];
        }

        // 2. Get API secret for HMAC verification
        $apiSecret = $this->config->getApiSecret();
        if (!$apiSecret) {
            $this->logger->warning('[Dominate_ErpConnector] Auth validation failed: wrong_api_credentials');
            return ['Error' => true, 'ErrorCode' => 'wrong_api_credentials'];
        }

        // 3. Verify timestamp is within valid window
        if (!$this->hmac->isTimestampValid($timestamp)) {
            $this->logger->warning('[Dominate_ErpConnector] Auth validation failed: stale_request');
            return ['Error' => true, 'ErrorCode' => 'stale_request'];
        }

        // 4. Verify HMAC signature
        if (!$this->hmac->verify($apiKey, $timestamp, $signature, $apiSecret)) {
            $this->logger->warning('[Dominate_ErpConnector] Auth validation failed: invalid_signature');
            return ['Error' => true, 'ErrorCode' => 'invalid_signature'];
        }

        return ['Error' => false];
    }
}

