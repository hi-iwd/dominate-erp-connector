<?php

namespace Dominate\ErpConnector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Support block.
 */
class Support extends Field
{
    /**
     * @var string
     */
    private string $contactUrl = 'https://www.dominate.co/contact-us';

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return sprintf(
            '<span><a href="%s" target="_blank">%s</a></span>',
            $this->contactUrl,
            __('Contact Us')
        );
    }
}

