<?php

namespace App\Support;

use Dotenv\Dotenv;

/**
 * Explicit, immutable description of the project Hive is operating on.
 *
 * Replaces the previous boot-time mutation of the global environment: the
 * target project's .env is parsed into a local, allowlisted map so that
 * credentials never leak into $_ENV/putenv() and several projects can be
 * driven from a single process (e.g. an HTTP/GUI front-end).
 */
final class HiveContext
{
    /**
     * Only these keys are read from the target .env. Everything else
     * (PATH, DB_*, application secrets...) is deliberately ignored.
     */
    private const ENV_ALLOWLIST = [
        'ANTHROPIC_API_KEY',
        'NIGHTWATCH_TOKEN',
        'NIGHTWATCH_PROJECT_ID',
    ];

    /**
     * @param  array<string, string>  $env
     */
    private function __construct(
        public readonly string $path,
        private readonly array $env,
    ) {}

    public static function fromPath(string $path): self
    {
        return new self($path, self::readEnv($path));
    }

    public function env(string $key, ?string $default = null): ?string
    {
        return $this->env[$key] ?? $default;
    }

    public function anthropicApiKey(): ?string
    {
        return $this->env('ANTHROPIC_API_KEY');
    }

    public function nightwatchToken(): ?string
    {
        return $this->env('NIGHTWATCH_TOKEN');
    }

    public function nightwatchProjectId(): ?string
    {
        return $this->env('NIGHTWATCH_PROJECT_ID');
    }

    /**
     * @return array<string, string>
     */
    private static function readEnv(string $path): array
    {
        $file = $path . '/.env';

        if (! is_file($file)) {
            return [];
        }

        $parsed = Dotenv::parse((string) file_get_contents($file));

        return array_intersect_key($parsed, array_flip(self::ENV_ALLOWLIST));
    }
}
