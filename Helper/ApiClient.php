<?php

namespace Dominate\ErpConnector\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * API client for Laravel ERP Connector requests.
 */
class ApiClient extends AbstractHelper
{
    /**
     * HTTP request timeout in seconds.
     */
    private const TIMEOUT = 10;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ApiClient constructor.
     *
     * @param Context             $context
     * @param Config              $config
     * @param Curl                $curl
     * @param SerializerInterface $serializer
     * @param LoggerInterface     $logger
     */
    public function __construct(
        Context             $context,
        Config              $config,
        Curl                $curl,
        SerializerInterface $serializer,
        LoggerInterface     $logger
    ) {
        parent::__construct($context);
        $this->config     = $config;
        $this->curl       = $curl;
        $this->serializer = $serializer;
        $this->logger     = $logger;
    }

    /**
     * Send POST request to Laravel endpoint.
     *
     * @param string   $path API path (e.g., 'check-connection', 'orders')
     * @param array    $data Payload data
     * @param int|null $websiteId Website ID for API credentials scope
     * @return array|false Response array on success, false on failure
     */
    public function post(string $path, array $data = [], ?int $websiteId = null)
    {
        $apiKey    = $this->config->getApiKey($websiteId);
        $apiSecret = $this->config->getApiSecret($websiteId);

        if (!$apiKey || !$apiSecret) {
            return false;
        }

        $endpoint = $this->config->getEndpoint();
        $url      = rtrim($endpoint, '/') . '/' . ltrim($path, '/');

        try {
            $timestamp = time();
            $signature = hash_hmac('sha256', $apiKey . ':' . $timestamp, $apiSecret);

            $payload = array_merge($data, [
                'api_key'   => $apiKey,
                'timestamp' => $timestamp,
                'signature' => $signature,
            ]);

            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Expect:']);
            $this->curl->post($url, $this->serializer->serialize($payload));

            $status = $this->curl->getStatus();
            $body   = $this->curl->getBody();
            $result = $this->serializer->unserialize($body);

            // Check if JSON parsing failed
            if ($result === null) {
                $this->logger->warning('[Dominate_ErpConnector] Invalid JSON response', [
                    'url'    => $url,
                    'status' => $status,
                    'body'   => substr($body, 0, 200),
                ]);

                return false;
            }

            return [
                'status' => $status,
                'body'   => $body,
                'data'   => $result,
            ];
        } catch (\Exception $e) {
            $this->logger->error('[Dominate_ErpConnector] API request exception: ' . $e->getMessage(), [
                'url'  => $url,
                'type' => get_class($e),
            ]);

            return false;
        }
    }

    /**
     * Check if response indicates success.
     *
     * @param array|false $response
     * @return bool
     */
    public function isSuccess($response): bool
    {
        if (!$response || !is_array($response)) {
            return false;
        }

        $status = $response['status'] ?? 0;
        $data   = $response['data'] ?? [];

        // HTTP 100 (Continue) is an interim response that should be treated as success
        // when the actual response body contains valid data
        return in_array($status, [200, 100], true)
            && isset($data['Error'])
            && $data['Error'] === false;
    }

    /**
     * Get error code from response.
     *
     * @param array|false $response
     * @return string|null
     */
    public function getErrorCode($response): ?string
    {
        if (!$response || !is_array($response)) {
            return 'connect_error';
        }

        return $response['data']['ErrorCode'] ?? 'connect_error';
    }

    /**
     * Get response data.
     *
     * @param array|false $response
     * @return array|null
     */
    public function getResponseData($response): ?array
    {
        if (!$response || !is_array($response)) {
            return null;
        }

        return $response['data'] ?? null;
    }
}

