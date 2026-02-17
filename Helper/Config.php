<?php

namespace Dominate\ErpConnector\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Config helper.
 */
class Config extends AbstractHelper
{
    /**
     * XML path for enabled flag.
     */
    private const XML_PATH_ENABLED    = 'dominate_erpconnector/general/enabled';

    /**
     * XML path for the API key.
     */
    private const XML_PATH_API_KEY    = 'dominate_erpconnector/general/api_key';

    /**
     * XML path for the API secret.
     */
    private const XML_PATH_API_SECRET = 'dominate_erpconnector/general/api_secret';

    /**
     * XML path for the endpoint.
     */
    private const XML_PATH_ENDPOINT   = 'dominate_erpconnector/api/endpoint';

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * Config constructor.
     * @param Context $context
     * @param EncryptorInterface $encryptor
     */
    public function __construct(Context $context, EncryptorInterface $encryptor)
    {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }

    /**
     * Check if extension is enabled.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool
    {
        return (bool) $this->getValue(self::XML_PATH_ENABLED, $websiteId);
    }

    /**
     * Get the API key.
     * @param int|null $websiteId
     * @return string|null
     */
    public function getApiKey(?int $websiteId = null): ?string
    {
        return $this->getValue(self::XML_PATH_API_KEY, $websiteId);
    }

    /**
     * Get the API secret.
     * @param int|null $websiteId
     * @return string|null
     */
    public function getApiSecret(?int $websiteId = null): ?string
    {
        $value = $this->getValue(self::XML_PATH_API_SECRET, $websiteId);

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    /**
     * Get the endpoint.
     * @param int|null $websiteId
     * @return string|null
     */
    public function getEndpoint(?int $websiteId = null): ?string
    {
        return $this->getValue(self::XML_PATH_ENDPOINT, $websiteId);
    }

    /**
     * Fetch config value for default or website scope.
     *
     * @param string   $path
     * @param int|null $websiteId
     * @return string|null
     */
    private function getValue(string $path, ?int $websiteId = null): ?string
    {
        if ($websiteId !== null) {
            return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        }

        return $this->scopeConfig->getValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null);
    }
}

