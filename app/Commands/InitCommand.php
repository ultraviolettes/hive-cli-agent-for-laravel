<?php

namespace App\Commands;

use App\Services\HiveDetector;
use App\Support\HiveConfig;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    protected $signature = 'init {--path= : Path to Laravel project (default: current dir)}';

    protected $description = 'Initialize Hive in a Laravel project';

    public function handle(HiveDetector $detector): int
    {
        $path = $this->option('path') ?? getcwd();

        if (! file_exists("$path/artisan")) {
            $this->error("No Laravel project found at: $path");

            return self::FAILURE;
        }

        $this->info('🐝 Initializing Hive...');

        $stack = $detector->detect($path);
        $project = basename($path);
        $config = new HiveConfig($path);

        $config->init($project, $stack);

        $this->line('');
        $this->info("✅ Hive initialized for <comment>{$project}</comment>");
        $this->line('');
        $this->line('Stack detected: <comment>' . implode(', ', $stack) . '</comment>');
        $this->line('');
        $this->line('Next steps:');
        $this->line('  <comment>hive plan --github owner/repo</comment>   — plan from GitHub issues');
        $this->line('  <comment>hive fix --nightwatch</comment>            — fix Nightwatch exceptions');
        $this->line('  <comment>hive spawn feat/my-feature</comment>       — spawn a single agent');

        return self::SUCCESS;
    }
}
