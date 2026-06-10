<?php

namespace App\Commands\Concerns;

use App\Support\HiveConfig;

/**
 * Resolves the AI analysis timeout for the planning commands (plan, fix):
 * --timeout flag first, then "ai_timeout" in .hive.json, otherwise null so
 * the gateway applies its own default.
 */
trait ResolvesAiTimeout
{
    protected function aiTimeout(HiveConfig $config): ?int
    {
        if ($this->option('timeout') !== null) {
            return (int) $this->option('timeout');
        }

        $configured = $config->get('ai_timeout');

        return $configured !== null ? (int) $configured : null;
    }
}
