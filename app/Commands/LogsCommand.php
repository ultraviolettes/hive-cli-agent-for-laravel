<?php

namespace App\Commands;

use App\Commands\Concerns\ValidatesBranch;
use App\Support\HiveConfig;
use App\Support\HiveContext;
use App\Support\HiveState;
use LaravelZero\Framework\Commands\Command;

class LogsCommand extends Command
{
    use ValidatesBranch;

    protected $signature = 'logs {branch : Branch/bee whose log to show}
                                 {--lines=50 : Number of trailing lines to print}
                                 {--follow : Stream new log lines until interrupted}';

    protected $description = 'Show the log of a background bee';

    public function handle(): int
    {
        $context = HiveContext::fromPath(getcwd());
        $config = new HiveConfig($context->path);

        if (! $config->exists()) {
            $this->error('No .hive.json found. Run hive init first.');

            return self::FAILURE;
        }

        $branch = $this->argument('branch');
        if (! $this->validBranch($branch)) {
            return self::FAILURE;
        }

        $task = (new HiveState($context->path))->get($branch);
        $log = $task['log_path'] ?? $context->logPath($branch);

        if (! is_file($log)) {
            $this->error("No log for {$branch}. Run it in the background first (hive run {$branch} --background).");

            return self::FAILURE;
        }

        $meta = [];
        if (! empty($task['session_id'])) {
            $meta[] = "session {$task['session_id']}";
        }
        if (! empty($task['pid'])) {
            $meta[] = "pid {$task['pid']}";
        }

        $this->line("📜 <comment>{$branch}</comment>" . ($meta !== [] ? ' · ' . implode(' · ', $meta) : ''));
        $this->line("   {$log}");
        $this->line('');

        foreach ($this->tail($log, (int) $this->option('lines')) as $line) {
            $this->line($line);
        }

        if ($this->option('follow')) {
            $this->follow($log);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function tail(string $log, int $lines): array
    {
        $content = rtrim((string) file_get_contents($log), "\n");

        if ($content === '') {
            return [];
        }

        $all = explode("\n", $content);

        return $lines > 0 ? array_slice($all, -$lines) : $all;
    }

    /**
     * Stream appended lines until interrupted (Ctrl-C).
     */
    private function follow(string $log): void
    {
        $handle = fopen($log, 'r');

        if ($handle === false) {
            return;
        }

        fseek($handle, 0, SEEK_END);

        while (true) {
            $line = fgets($handle);

            if ($line === false) {
                usleep(250_000);
                clearstatcache(false, $log);

                continue;
            }

            $this->output->write($line);
        }
    }
}
