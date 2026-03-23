# 🐝 Hive

> The Laravel agent orchestration CLI.  
> `hive plan` turns your GitHub backlog into parallel Claude Code agents.  
> `hive fix` turns your Nightwatch exceptions into pull requests.

Hive is a **Laravel Zero** CLI that reads your GitHub issues or Nightwatch exceptions, analyzes task dependencies with AI, and spawns parallel [Claude Code](https://docs.anthropic.com/en/docs/claude-code) agents — each in its own git worktree with a task-specific `CLAUDE.md`.

## Why Hive?

Tools like [Polyscope](https://getpolyscope.com) (by BeyondCode) and [Superset](https://superset.sh) are excellent at running parallel agents. Polyscope in particular is deeply integrated with the Laravel ecosystem — Nightwatch, GitHub, autopilot mode. If you don't use it, you should try it.

Hive is a different kind of tool. It's not an app — it's a CLI that fits into any workflow.

**The key difference: hierarchical task planning.**

Hive reads your existing issues or exceptions and reasons about their dependencies before spawning anything:

- *"This feature depends on that security patch — spawn the patch first, block the feature until it's done."*
- *"These 4 bug fixes are independent — spawn them all in parallel right now."*
- *"This Nightwatch exception happens in `UserController:42` with 150 occurrences — inject the exact stacktrace as context for the fix agent."*

```bash
# Analyzes dependencies, builds a DAG, spawns in the right order
hive plan --github owner/repo --milestone "Sprint 4"

# Fetches unresolved exceptions, sorts by impact, spawns fix agents
hive fix --nightwatch
```

Because it's a CLI, it works with Polyscope, Superset, your terminal, or anything else.

## How it works

```
GitHub Issues / Nightwatch Exceptions
            │
            ▼
    QueenBee (DagAnalyzer)
    Analyzes dependencies → builds execution DAG
    Priority: security (100) > bugs (60) > features (30)
            │
            ▼
    Ready tasks spawned in parallel
    Blocked tasks wait for their dependencies
            │
            ▼
    Each worktree gets a CLAUDE.md with:
      · Task description & context
      · Stack info (Filament, Pest, Livewire…)
      · TDD instructions if Pest detected
      · Exact stacktrace if from Nightwatch
      · Conventional commit prefix
```

## Requirements

- PHP 8.2+
- [gh CLI](https://cli.github.com/) — for GitHub issues ingestion
- [Claude Code](https://docs.anthropic.com/en/docs/claude-code) — to run agents in worktrees (also used headlessly for DAG analysis)
- `ANTHROPIC_API_KEY` in your Laravel project's `.env` — only needed if Claude Code CLI is not installed

## Installation

```bash
composer global require ultraviolettes/hive-cli-agent-for-laravel
```

Or clone and build locally:

```bash
git clone https://github.com/ultraviolettes/hive-cli-agent-for-laravel.git
cd hive-cli-agent-for-laravel
composer install
php hive app:build hive
# → builds/hive (standalone PHAR)
```

## Quick start

```bash
cd ~/your-laravel-project

# 1. Initialize Hive (detects your stack automatically)
hive init

# 2. Plan from GitHub issues
hive plan --github owner/repo
hive plan --github owner/repo --milestone "Sprint 4"

# 3. Or fix Nightwatch exceptions
hive fix --nightwatch

# 4. Or spawn a single agent manually
hive spawn feat/my-feature --context "Build the admin dashboard"

# 5. Check what's running
hive status

# 6. Commit, push and open a PR from a worktree
hive pr

# 7. Clean up after merge
hive harvest feat/my-feature
```

## Commands

### `hive init`
Detects your Laravel stack (Filament, Pest, Nightwatch, Vite, Livewire, Horizon, Inertia) and creates a `.hive.json` config file.

```bash
hive init
hive init --path /path/to/project
```

### `hive plan`
Reads a backlog (GitHub issues or raw text), builds a dependency graph, displays the execution plan, and spawns agents for all ready tasks.

```bash
hive plan --github owner/repo
hive plan --github owner/repo --milestone "Sprint 4"
hive plan --text "Fix the login bug, then add dark mode, then refactor auth"
hive plan --github owner/repo --dry-run   # preview without spawning
```

**The DAG in practice:**

| # | Branch | Priority | Status | Depends on |
|---|--------|----------|--------|------------|
| 1 | fix/composer-cve | 100 | 🟡 ready | — |
| 2 | fix/npm-audit | 100 | 🟡 ready | — |
| 3 | chore/laravel-update | 70 | 🔒 blocked | #1, #2 |
| 4 | feat/filament-v5 | 30 | 🔒 blocked | #3 |

Tasks 1 and 2 spawn immediately in parallel. Tasks 3 and 4 wait.

### `hive fix --nightwatch`
Fetches unresolved exceptions from [Laravel Nightwatch](https://nightwatch.laravel.com), sorts by impact (occurrences), and spawns fix agents with the exact stacktrace injected as context.

```bash
hive fix --nightwatch
hive fix --nightwatch --limit 5
hive fix --nightwatch --dry-run
```

Requires `NIGHTWATCH_TOKEN` and `NIGHTWATCH_PROJECT_ID` in your `.env`.

### `hive spawn`
Spawns a single agent in an isolated git worktree.

```bash
hive spawn feat/my-feature
hive spawn fix/login-bug --context "Users can't login when 2FA is enabled"
```

### `hive pr`
From inside a worktree — commits staged changes, pushes, and opens a PR via `gh`.

```bash
hive pr         # current worktree
hive pr --all   # all worktrees with unpushed commits
```

### `hive status`
Lists all active Hive worktrees with branch, last commit, and whether an agent is running.

### `hive harvest`
Removes a worktree after the branch has been merged.

## Configuration

`.hive.json` created by `hive init` at your Laravel project root:

```json
{
    "project": "my-app",
    "stack": ["laravel", "filament", "pest", "nightwatch", "vite"],
    "main_branch": "main",
    "worktrees_path": ".hive/worktrees"
}
```

Environment variables (in your Laravel project's `.env`):

| Variable | Required for | Notes |
|----------|-------------|-------|
| `ANTHROPIC_API_KEY` | `plan`, `fix` | Not needed if Claude Code CLI is installed |
| `NIGHTWATCH_TOKEN` | `fix --nightwatch` | Nightwatch API token |
| `NIGHTWATCH_PROJECT_ID` | `fix --nightwatch` | Nightwatch project ID |

## Architecture

```
app/
├── Ai/Agents/
│   └── DagAnalyzerAgent.php        — laravel/ai structured output (fallback)
├── Commands/
│   ├── InitCommand.php             — hive init
│   ├── PlanCommand.php             — hive plan
│   ├── FixCommand.php              — hive fix
│   ├── SpawnCommand.php            — hive spawn
│   ├── HarvestCommand.php          — hive harvest
│   ├── StatusCommand.php           — hive status
│   └── PrCommand.php               — hive pr
├── Services/
│   ├── ClaudeCodeGateway.php       — runs `claude -p` headless, parses JSON output
│   ├── DagAnalyzer.php             — ClaudeCode first, laravel/ai fallback
│   ├── HiveDetector.php            — auto-detects Laravel stack
│   ├── WorktreeManager.php         — git worktree spawn/harvest/list
│   ├── WorktreeInspector.php       — reads worktree state
│   ├── ContextBuilder.php          — generates CLAUDE.md per task
│   ├── GithubIngester.php          — fetches issues via gh CLI
│   └── NightwatchIngester.php      — fetches exceptions via API
└── Support/
    └── HiveConfig.php              — reads/writes .hive.json
```

## Testing

```bash
./vendor/bin/pest
```

## Built with

- [Laravel Zero](https://laravel-zero.com/) — CLI framework
- [Laravel AI SDK](https://laravel.com/docs/12.x/ai-sdk) — structured output agent
- [Laravel Prompts](https://laravel.com/docs/12.x/prompts) — terminal UX
- [Pest](https://pestphp.com/) — testing

## Status

🚧 Early — looking for contributors and real-world testers.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the roadmap and how to help.

## License

MIT
