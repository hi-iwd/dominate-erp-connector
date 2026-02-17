<?php

namespace Dominate\ErpConnector\Block\Adminhtml\System\Config;

use Dominate\ErpConnector\Helper\Config;
use Dominate\ErpConnector\Helper\ConnectionChecker;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Connection status block.
 */
class ConnectionStatus extends Field
{
    /**
     * @var ConnectionChecker
     */
    private ConnectionChecker $connectionChecker;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * ConnectionStatus constructor.
     * @param Context $context
     * @param ConnectionChecker $connectionChecker
     * @param Config $config
     * @param array $data
     */
    public function __construct(
        Context $context,
        ConnectionChecker $connectionChecker,
        Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->connectionChecker = $connectionChecker;
        $this->config = $config;
    }

    /**
     * Render the field (hide entire field including label if credentials are empty).
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $websiteId      = $this->getRequest()->getParam('website');
        $websiteIdParam = $websiteId ? (int) $websiteId : null;

        // Check if credentials exist before rendering
        $apiKey    = $this->config->getApiKey($websiteIdParam);
        $apiSecret = $this->config->getApiSecret($websiteIdParam);

        if (!$apiKey || !$apiSecret) {
            // Hide the entire field (including label) if credentials are empty
            return '';
        }

        return parent::render($element);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $websiteId      = $this->getRequest()->getParam('website');
        $websiteIdParam = $websiteId ? (int) $websiteId : null;

        $result = $this->connectionChecker->test($websiteIdParam);

        if ($result['status']) {
            $message = '<b style="color:#059147;">' . __('ERP Connector is Successfully Connected!') . '</b>';
            $comment = '';
        } else {
            $errorMessage = $result['message'] ?? __('Connection failed');
            $errorCode    = $result['code'] ?? 'connect_error';
            $message      = '<b style="color:#D40707;">' . $errorMessage . '</b>';
            $comment      = $this->getTroubleshootingComment($errorCode);
        }

        $html = '<span>' . $message . '</span>';
        if ($comment) {
            $html .= '<p class="note"><span>' . $comment . '</span></p>';
        }

        return $html;
    }

    /**
     * Get troubleshooting comment based on error code.
     * @param string $errorCode
     * @return string
     */
    private function getTroubleshootingComment(string $errorCode): string
    {
        $dashboardUrl  = 'https://dominate.co/account';
        $contactUrl    = 'https://www.dominate.co/contact-us';
        $dashboardLink = sprintf('<a href="%s" target="_blank">%s</a>', $dashboardUrl, __('Dominate ERP Connector Dashboard'));
        $contactLink   = sprintf('<a href="%s" target="_blank">%s</a>', $contactUrl, __('contact support'));

        $comments = [
            'empty_creds' => sprintf(
                __('Please enter your API Key and API Secret. You can find these credentials in your %s.'),
                $dashboardLink
            ),
            'empty_endpoint' => __('Please configure the connector endpoint URL in the configuration.'),
            'missing_params' => sprintf(
                __('Required connection parameters are missing. Please verify your API Key, API Secret, and endpoint are configured correctly in the %s.'),
                $dashboardLink
            ),
            'wrong_api_credentials' => sprintf(
                __('The API Key or API Secret is incorrect. Please verify your credentials in the %s and ensure they match exactly.'),
                $dashboardLink
            ),
            'wrong_website_url' => sprintf(
                __('The website URL does not match the one registered in your Dominate account. Please update your website URL in the %s.'),
                $dashboardLink
            ),
            'wrong_platform' => __('Platform mismatch detected. Please ensure you are using the correct API credentials for Magento 2.'),
            'invalid_signature' => sprintf(
                __('HMAC signature validation failed. Please verify your API Secret is correct in the %s and has not been changed.'),
                $dashboardLink
            ),
            'stale_request' => __('Request timestamp is too old. Please refresh the page and try again.'),
            'connect_error' => sprintf(
                __('Unable to reach the Dominate ERP Connector service. Please check if your server supports whitelisting the Dominate ERP Connector App (URL: connector.dominate.co | IP: 209.126.24.26). If the issue persists, %s.'),
                $contactLink
            ),
            'exception' => sprintf(
                __('An unexpected error occurred. Please check your server logs for more details or %s.'),
                $contactLink
            ),
        ];

        $fallback = sprintf(
            __('Please verify your API credentials and try again. If the issue persists, %s.'),
            $contactLink
        );

        return $comments[$errorCode] ?? $fallback;
    }
}

