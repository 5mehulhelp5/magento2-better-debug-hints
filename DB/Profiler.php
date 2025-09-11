<?php

namespace KingfisherDirect\BetterDebugHints\DB;

use Magento\Framework\DB\Profiler as MagentoProfiler;

class Profiler extends MagentoProfiler
{
    private array $extraData = [];

    public function queryStart($queryText, $queryType = null)
    {
        $profileIndex = parent::queryStart($queryText, $queryType);

        $this->extraData[$profileIndex] = [
            'backtrace' => debug_backtrace(),
        ];

        return $profileIndex;
    }

    public function getExtraData(\Zend_Db_Profiler_Query $query): array
    {
        $index = array_search($query, $this->_queryProfiles);

        return $this->extraData[$index] ?? [];
    }
}
