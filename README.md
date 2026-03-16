# Hive CLI

> The Laravel agent orchestration CLI.
> `hive plan` turns your GitHub backlog into parallel agents.
> `hive fix` turns your Nightwatch exceptions into pull requests.

Hive is a **Laravel Zero** CLI that reads your GitHub issues or Nightwatch exceptions, analyzes task dependencies with AI, and spawns parallel [Claude Code](https://docs.anthropic.com/en/docs/claude-code) agents — each in its own git worktree with a task-specific `CLAUDE.md`.

## How it works

```
GitHub Issues / Nightwatch Exceptions
            |
            v
    QueenBee AI (laravel/ai)
    Analyzes dependencies → builds execution DAG
            |
            v
    Parallel worktrees spawned
    Each agent gets a CLAUDE.md with:
      - Task description & context
      - Stack info (Filament, Pest, Livewire...)
      - TDD instructions if Pest is detected
      - Conventional commit prefix
```

## Requirements

- PHP 8.2+
- [gh CLI](https://cli.github.com/) (for GitHub issues ingestion)
- [Claude Code](https://docs.anthropic.com/en/docs/claude-code) (to run agents in worktrees)
- An `ANTHROPIC_API_KEY` in your Laravel project's `.env`

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

# 4. Or spawn a single agent
hive spawn feat/my-feature --context "Build the admin dashboard"

# 5. Check what's running
hive status

# 6. Clean up after merge
hive harvest feat/my-feature
```

## Commands

### `hive init`

Initializes Hive in a Laravel project. Detects your stack (Laravel, Filament, Pest, Nightwatch, Vite, Livewire, Horizon, Inertia) and creates a `.hive.json` config file.

```bash
hive init
hive init --path /path/to/project
```

### `hive plan`

Reads a backlog (GitHub issues or raw text), sends it to the **QueenBee AI** for dependency analysis, displays an execution plan, and spawns agents for all ready tasks.

```bash
# From GitHub issues
hive plan --github owner/repo
hive plan --github owner/repo --milestone "Sprint 4"

# From raw text
hive plan --text "Fix the login bug, then add dark mode, then refactor the auth middleware"

# Preview without spawning
hive plan --github owner/repo --dry-run
```

The AI analyzes tasks and returns a DAG with:
- **Priority** — security (100) > dependencies (70) > bugs (60) > features (30)
- **Dependencies** — blocked tasks wait for their dependencies
- **Parallelism** — independent tasks are spawned simultaneously

### `hive fix --nightwatch`

Fetches unresolved exceptions from [Laravel Nightwatch](https://nightwatch.laravel.com), sorts them by impact, and spawns fix agents.

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

### `hive status`

Lists all active Hive worktrees.

```bash
hive status
```

### `hive harvest`

Removes a worktree after the branch has been merged.

```bash
hive harvest feat/my-feature
```

## Configuration

`hive init` creates a `.hive.json` at the root of your Laravel project:

```json
{
    "project": "my-app",
    "stack": ["laravel", "filament", "pest", "nightwatch", "vite"],
    "main_branch": "main",
    "worktrees_path": ".hive/worktrees"
}
```

Environment variables (in your Laravel project's `.env`):

| Variable | Required for | Description |
|----------|-------------|-------------|
| `ANTHROPIC_API_KEY` | `plan`, `fix` | Claude API key for DAG analysis |
| `NIGHTWATCH_TOKEN` | `fix --nightwatch` | Nightwatch API token |
| `NIGHTWATCH_PROJECT_ID` | `fix --nightwatch` | Nightwatch project ID |
| `GITHUB_TOKEN` | `plan --github` | GitHub token (if `gh` CLI isn't authenticated) |

## Architecture

```
app/
├── Ai/Agents/
│   └── DagAnalyzerAgent.php        — laravel/ai structured output agent
├── Commands/
│   ├── InitCommand.php             — hive init
│   ├── PlanCommand.php             — hive plan
│   ├── FixCommand.php              — hive fix
│   ├── SpawnCommand.php            — hive spawn
│   ├── HarvestCommand.php          — hive harvest
│   └── StatusCommand.php           — hive status
├── Services/
│   ├── HiveDetector.php            — auto-detects Laravel stack
│   ├── WorktreeManager.php         — git worktree operations
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

29 tests covering all services, commands, the AI agent, and architecture rules.

## Built with

- [Laravel Zero](https://laravel-zero.com/) — CLI framework
- [Laravel AI](https://laravel.com/docs/12.x/ai-sdk) — AI agent with structured output
- [Laravel Prompts](https://laravel.com/docs/12.x/prompts) — Terminal UX (spinners, tables, confirmations)
- [Pest](https://pestphp.com/) — Testing framework

## License

MIT
