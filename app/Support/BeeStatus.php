<?php

namespace App\Support;

/**
 * Lifecycle status of a bee (a worktree's agent), derived by WorktreeInspector.
 *
 * Returned as a typed value so callers (and a future GUI) branch on the enum
 * instead of parsing emoji strings. label() is only for terminal display.
 */
enum BeeStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case ChangesPending = 'changes_pending';
    case Idle = 'idle';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Running => '🐝 agent running',
            self::Done => '✅ done',
            self::ChangesPending => '🔧 changes pending',
            self::Idle => '💤 idle',
            self::Unknown => '❓ unknown',
        };
    }
}
