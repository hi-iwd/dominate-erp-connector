<?php

namespace Dominate\ErpConnector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Dashboard block.
 */
class Dashboard extends Field
{
    /**
     * @var string
     */
    private string $dashboardUrl = 'https://dominate.co/account';

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return sprintf(
            '<button type="button" class="primary action-default scalable" onclick="window.open(\'%s\', \'_blank\')">%s</button>',
            $this->dashboardUrl,
            __('Open My Dashboard')
        );
    }
}

