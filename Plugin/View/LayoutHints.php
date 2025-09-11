<?php

namespace KingfisherDirect\BetterDebugHints\Plugin\View;

use KingfisherDirect\BetterDebugHints\DB\Profiler as BdhProfiler;
use KingfisherDirect\BetterDebugHints\Helper\Config;
use Magento\Backend\Helper\Data;
use Magento\Cms\Block\Widget\Block as WidgetBlock;
use Magento\Framework\App\Cache\StateInterface as CacheStateInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Profiler;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\Element;

class LayoutHints
{
    private ?Layout $layout;

    private string $blockEditUrl;

    private bool $isEnabled;

    private false|Profiler $dbProfiler;

    private array $timings = [];

    private array $allDbProfiles = [];

    private array $elProfiles = [];

    public function __construct(
        Layout $layout,
        Config $config,
        Data $helperBackend,
        private SecureHtmlRenderer $secureHtmlRenderer,
        private ResourceConnection $resourceConnection,
        private Repository $assets,
        private CacheInterface $cache,
        private CacheStateInterface $cacheState,
    ) {
        $this->blockEditUrl = $helperBackend->getUrl('cms/block/edit', ['block_id' => '__id__']);
        $this->isEnabled = $config->isHintEnabled();
        $this->layout = $this->isEnabled ? $layout : null;
    }

    public function aroundRenderElement(Layout $layout, \Closure $proceed, string $name, $useCache = true): string
    {
        $startProfiles = $name === 'root'
            ? []
            : $this->collectDBQueryProfiles();

        $start = hrtime(true);
        $html = $proceed($name, $useCache);
        $end = hrtime(true);
        $total = $end - $start;
        $this->timings[$name] = $total;

        $endProfiles = $this->collectDBQueryProfiles();

        $elProfiles = array_udiff(
            $endProfiles,
            $startProfiles,
            $this->allDbProfiles,
            fn (\Zend_Db_Profiler_Query $a, \Zend_Db_Profiler_Query $b) => $a === $b ? 0 : $a->getStartedMicrotime() <=> $b->getStartedMicrotime()
        );
        $this->elProfiles[$name] = $elProfiles;

        array_push($this->allDbProfiles, ...$elProfiles);

        if (!$this->isEnabled || !$html || !trim($html)) {
            return $html;
        }

        if ($layout->getElementProperty($name, Element::CONTAINER_OPT_HTML_TAG)) {
            $label = "";

            if ($layout->getElementProperty($name, Element::CONTAINER_OPT_LABEL)) {
                $label = " data-mage-debug-label='$label'";
            }

            $html = preg_replace("@^(<[a-z0-9\-\_]+)[\s>]@i", "\${1} data-mage-debug='$name'$label ", $html);
        }

        if ($name === 'root') {
            $html = $this->getRootScript() . $html;
        }

        return
            "<script type='text/mage-debug' data-mage-debug-position='start' data-mage-debug='$name'></script>" .
            $html .
            "<script type='text/mage-debug' data-mage-debug-position='end' data-mage-debug='$name'></script>";
    }

    private function getRootScript(): string
    {
        $structure = $this->getStructure();
        $structureJson = json_encode($structure);
        $blockEditUrl = $this->getBlockEditUrl();

        return $this->secureHtmlRenderer->renderTag('script', ['type' => 'text/javascript'], <<<JS
            require(['KingfisherDirect_BetterDebugHints/js/LayoutHints'], function (LayoutHints) {
                var layoutHints = new LayoutHints({$structureJson}, {
                    blockEditUrl: "{$blockEditUrl}",
                });

                if (!window.layout) {
                    window.layout = layoutHints.inspect.bind(layoutHints)
                }

                if (!window.lh) {
                    window.lh = layoutHints
                }
            });
        JS, false);
    }

    private function getBlockEditUrl(): string
    {
        return $this->blockEditUrl;
    }

    private function getStructure($name = 'root'): array
    {
        $result = [];

        if (!$this->layout) {
            throw new \LogicException("Module is not enabled or layout prop is missing for another reason");
        }

        if ($name === 'root') {
            $result['handles'] = $this->layout->getUpdate()->getHandles();
        }

        if ($label = $this->layout->getElementProperty($name, Element::CONTAINER_OPT_LABEL)) {
            $result['label'] = $label;
        }

        $alias = $this->layout->getElementAlias($name);

        if ($alias && $alias !== $name) {
            $result['alias'] = $alias;
        }

        $childNames = $this->layout->getChildNames($name);

        if (count($childNames) > 0) {
            $result['children'] = [];
        }

        foreach ($childNames as $child) {
            $result['children'][$child] = $this->getStructure($child);
        }

        $block = $this->layout->getBlock($name);

        if ($block instanceof AbstractBlock) {
            $result['block'] = $this->getBlockInfo($block);
        }

        if ($block instanceof WidgetBlock) {
            $result['blockId'] = $block->getBlockId();
        }

        if ($this->timings[$name] ?? null) {
            $result['timings'] = [
                'total' => $this->timings[$name],
            ];
        }

        if ($this->elProfiles[$name] ?? null) {
            $result['db'] = [
                'profiles' => array_values(array_map([$this, 'normalizeDbQueryProfile'], $this->elProfiles[$name])),
            ];
        }

        return $result;
    }

    private function getBlockInfo(AbstractBlock $block)
    {
        return [
            'class' => $this->getBlockClass($block),
            'template' => $block->getTemplateFile(),
            'moduleName' => $block->getModuleName(),
            'nameInLayout' => $block->getNameInLayout(),
            'cache' => $this->getCacheInfo($block)
        ];
    }

    private function getCacheInfo(AbstractBlock $block): array
    {
        $reflection = new \ReflectionClass($block);
        $cacheLifetimeMethod = $reflection->getMethod('getCacheLifetime');
        $cacheLifetimeMethod->setAccessible(true);

        $cacheLifetime = $cacheLifetimeMethod->invoke($block);

        if ($cacheLifetime === null) {
            return [
                'enabled' => false,
            ];
        }

        $cacheKeyInfo = $block->getCacheKeyInfo();
        $cacheKey = $block->getCacheKey();

        // Check if block cache group is enabled using injected cache state
        if (!$this->cacheState->isEnabled(\Magento\Framework\View\Element\AbstractBlock::CACHE_GROUP)) {
            return [
                'hit' => false,
                'enabled' => false,
                'lifetime' => $cacheLifetime,
                'key' => $cacheKey,
                'keyInfo' => $cacheKeyInfo
            ];
        }

        // Use injected cache to check for cached data
        $cacheStart = hrtime(true);
        $cachedData = $this->cache->load($cacheKey);
        $cacheEnd = hrtime(true);
        $total = $cacheEnd - $cacheStart;

        return [
            'hit' => !empty($cachedData),
            'enabled' => true,
            'lifetime' => $cacheLifetime,
            'key' => $cacheKey,
            'time' => $total,
            'keyInfo' => $cacheKeyInfo
        ];
    }

    private function getDbProfiler(): ?Profiler
    {
        if (isset($this->dbProfiler)) {
            return $this->dbProfiler ?: null;
        }

        $connection = $this->resourceConnection->getConnection('read');
        if (!$connection instanceof \Zend_Db_Adapter_Abstract) {
            $this->dbProfiler = false;
            return null;
        }

        $profiler = $connection->getProfiler();
        if (!($profiler instanceof Profiler)) {
            $this->dbProfiler = false;
            return null;
        }

        return $this->dbProfiler = $profiler;
    }

    private function collectDBQueryProfiles(): array
    {
        $profiler = $this->getDbProfiler();

        return $profiler?->getQueryProfiles() ?: [];
    }

    private function normalizeDbQueryProfile(\Zend_Db_Profiler_Query $query): array
    {
        $profiler = $this->getDbProfiler();

        $extraData = $profiler instanceof BdhProfiler
            ? $profiler->getExtraData($query)
            : [];

        return array_merge([
            'query' => $query->getQuery(),
            'type' => $query->getQueryType(),
            'elapsed' => $query->getElapsedSecs() * 1000,
            'params' => $query->getQueryParams(),
        ], $extraData);
    }

    /**
     * Copyright (c) 2016, H&O E-commerce specialisten B.V.
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
     * following conditions are met:
     *
     * 1. Redistributions of source code must retain the above copyright notice, this list of conditions and the
     *    following disclaimer.
     *
     * 2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the
     *    following disclaimer in the documentation and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED
     * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
     * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
     * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
     * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
     * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING
     * IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     */
    private function getBlockClass(AbstractBlock $block)
    {
        $className = get_class($block);

        if ($block instanceof InterceptorInterface) {
            $reflector = new \ReflectionClass($block); //@codingStandardsIgnoreLine
            $className = $reflector->getParentClass()->getName();
        }

        return $className;
    }
}
