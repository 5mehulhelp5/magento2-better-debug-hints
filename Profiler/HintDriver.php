<?php

namespace KingfisherDirect\BetterDebugHints\Profiler;

use Magento\Framework\Profiler\DriverInterface;

class HintDriver implements DriverInterface
{
    private array $activeTimers = [];

    private array $collected = [];

    public function start($timerId, ?array $tags = null)
    {
        $this->activeTimers[$timerId] = [$tags, microtime(true)];
    }

    public function stop($timerId)
    {
        if (!isset($this->activeTimers[$timerId])) {
            return;
        }

        $this->activeTimers[$timerId][2] = microtime(true);
    }

    public function clear($timerId = null)
    {
        $this->activeTimers = [];
    }

    public function collect(): array
    {
        $timers = $this->activeTimers;
        array_merge($this->collected, $timers);
        $this->activeTimers = [];

        return $timers;
    }
}
