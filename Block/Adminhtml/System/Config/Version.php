<?php

namespace Dominate\ErpConnector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;

/**
 * Version block.
 */
class Version extends Field
{
    /**
     * @var ComponentRegistrar
     */
    private ComponentRegistrar $componentRegistrar;

    /**
     * Version constructor.
     * @param Context $context
     * @param ComponentRegistrar $componentRegistrar
     * @param array $data
     */
    public function __construct(
        Context $context,
        ComponentRegistrar $componentRegistrar,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->componentRegistrar = $componentRegistrar;
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $moduleName = 'Dominate_ErpConnector';
        $configFile = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName)
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'module.xml';

        if (!file_exists($configFile)) {
            return '<span>N/A</span>';
        }

        try {
            $xml = new \SimpleXMLElement(file_get_contents($configFile));
            $version = (string) $xml->module[0]->attributes()->setup_version;
        } catch (\Exception $e) {
            return '<span>N/A</span>';
        }

        return '<span>' . htmlspecialchars($version, ENT_QUOTES | ENT_HTML5) . '</span>';
    }
}

