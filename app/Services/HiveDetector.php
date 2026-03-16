<?php

namespace App\Services;

final class HiveDetector
{
    public function detect(string $path): array
    {
        $stack = [];
        $composer = $this->readComposer($path);

        if (file_exists("$path/artisan")) {
            $stack[] = 'laravel';
        }
        if (is_dir("$path/app/Filament")) {
            $stack[] = 'filament';
        }
        if (file_exists("$path/vendor/bin/pest")) {
            $stack[] = 'pest';
        }
        if (file_exists("$path/vite.config.js") || file_exists("$path/vite.config.ts")) {
            $stack[] = 'vite';
        }
        if ($this->composerHas($composer, 'laravel/nightwatch')) {
            $stack[] = 'nightwatch';
        }
        if ($this->composerHas($composer, 'laravel/horizon')) {
            $stack[] = 'horizon';
        }
        if ($this->composerHas($composer, 'livewire/livewire')) {
            $stack[] = 'livewire';
        }
        if ($this->composerHas($composer, 'inertiajs/inertia-laravel')) {
            $stack[] = 'inertia';
        }

        return $stack;
    }

    private function readComposer(string $path): array
    {
        $file = "$path/composer.json";

        return file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    }

    private function composerHas(array $composer, string $package): bool
    {
        $all = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        return isset($all[$package]);
    }
}
