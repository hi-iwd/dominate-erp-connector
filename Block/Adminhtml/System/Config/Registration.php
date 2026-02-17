<?php

namespace Dominate\ErpConnector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Registration block.
 */
class Registration extends Field
{
    /**
     * @var string
     */
    private string $registrationUrl = 'https://dominate.co';

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return sprintf(
            '<span><a href="%s" target="_blank">%s</a></span>',
            $this->registrationUrl,
            __('Register')
        );
    }
}

