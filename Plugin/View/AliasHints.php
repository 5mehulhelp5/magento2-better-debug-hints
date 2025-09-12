<?php

namespace KingfisherDirect\BetterDebugHints\Plugin\View;

use KingfisherDirect\BetterDebugHints\Helper\Config;
use Magento\Framework\View\Element\AbstractBlock;

class AliasHints
{
    private bool $isEnabled;

    public function __construct(Config $config)
    {
        $this->isEnabled = $config->isHintEnabled();
    }

    public function aroundGetChildHtml(AbstractBlock $block, \Closure $proceed, string $alias = '', $useCache = true)
    {
        $html = $proceed($alias, $useCache);

        if (!$this->isEnabled || !trim($html)) {
            return $html;
        }

        $layout = $block->getLayout();
        $name = $block->getNameInLayout();
        $childName = $layout->getChildName($name, $alias);

        return
            "<script type='text/mage-debug' data-mage-debug-position='start' data-mage-debug='$childName'></script>" .
            $html .
            "<script type='text/mage-debug' data-mage-debug-position='end' data-mage-debug='$childName'></script>";
    }
}
