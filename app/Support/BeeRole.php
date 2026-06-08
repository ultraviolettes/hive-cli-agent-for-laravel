<?php

namespace App\Support;

/**
 * Specialised role of a bee. The Desktop renders bees by role; the CLI assigns
 * one per task (stable after the first plan) and falls back to Fullstack.
 */
enum BeeRole: string
{
    case Architect = 'architect';
    case Fullstack = 'fullstack';
    case Frontend = 'frontend';
    case Backend = 'backend';
    case Qa = 'qa';
    case Security = 'security';
    case Devops = 'devops';

    /**
     * Parse a role name, returning null for missing or invalid values.
     */
    public static function tryFromName(?string $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
