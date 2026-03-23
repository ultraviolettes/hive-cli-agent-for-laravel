# Hive CLI — Claude Code Context

> "Let your bees build."

Laravel Zero CLI that turns GitHub backlogs and Nightwatch exceptions into parallel Claude Code agents.

---

## Vocabulary

| Term | Reality |
|---|---|
| **Hive** | A Laravel project registered with Hive (has `.hive.json`) |
| **Bee** | A git worktree = one isolated Claude Code agent |
| **QueenBee** | The AI orchestrator that analyzes tasks and builds the DAG |
| **Spawn** | Create a new Bee (worktree + inject CLAUDE.md) |
| **Harvest** | Remove a worktree after merge |
| **DAG** | Directed Acyclic Graph — the dependency tree between tasks |

---

## Stack

- **Laravel Zero** — CLI micro-framework (`laravel-zero/framework`)
- **Laravel Prompts** — Terminal UX (spinners, tables, confirm, select)
- **laravel/ai** — Structured output agent (`DagAnalyzerAgent`) — fallback when Claude Code CLI not available
- **ClaudeCodeGateway** — Uses `claude -p --output-format json` headless mode as **primary AI provider**
- **Pest** — TDD, always test first

---

## Architecture

```
app/
├── Ai/Agents/
│   └── DagAnalyzerAgent.php        — laravel/ai fallback agent (HasStructuredOutput)
├── Commands/
│   ├── InitCommand.php             — hive init
│   ├── PlanCommand.php             — hive plan --github / --text
│   ├── FixCommand.php              — hive fix --nightwatch
│   ├── SpawnCommand.php            — hive spawn <branch>
│   ├── HarvestCommand.php          — hive harvest <branch>
│   ├── StatusCommand.php           — hive status
│   └── PrCommand.php               — hive pr (commit + push + open PR)
├── Services/
│   ├── ClaudeCodeGateway.php       — runs `claude -p` headless, parses JSON
│   ├── DagAnalyzer.php             — orchestrates AI: ClaudeCode first, laravel/ai fallback
│   ├── HiveDetector.php            — auto-detects Laravel stack
│   ├── WorktreeManager.php         — git worktree spawn/harvest/list
│   ├── WorktreeInspector.php       — reads worktree state (branch, changes, agent running)
│   ├── ContextBuilder.php          — generates CLAUDE.md per task
│   ├── GithubIngester.php          — fetches issues via gh CLI
│   └── NightwatchIngester.php      — fetches exceptions via Nightwatch API
└── Support/
    └── HiveConfig.php              — reads/writes .hive.json
```

---

## Key Design Decisions

**ClaudeCodeGateway as primary AI provider**
`claude -p "..." --output-format json` runs Claude Code headlessly and returns structured JSON. This means no API key needed if the user has Claude Code installed. `laravel/ai` (DagAnalyzerAgent) is the fallback when Claude Code is not available.

**DagAnalyzer orchestrates both**
`DagAnalyzer::analyze()` tries ClaudeCode first, falls back to laravel/ai. Commands use `DagAnalyzer`, never the agent directly.

**`.hive.json` at project root**
All Hive state lives in `.hive.json` in the target Laravel project. Hive itself is stateless — run it from any Laravel project directory.

**CLAUDE.md injected per worktree**
`ContextBuilder::writeContext()` generates a task-specific `CLAUDE.md` in each worktree with: task description, stack info, TDD instructions (if Pest), commit prefix, issue number or exception details.

---

## Commands Overview

```bash
hive init                                   # detect stack, create .hive.json
hive plan --github owner/repo               # GitHub issues → DAG → spawn agents
hive plan --github owner/repo --milestone x # filter by milestone
hive plan --text "my backlog"               # raw text → DAG → spawn agents
hive plan --dry-run                         # show plan without spawning
hive fix --nightwatch                       # Nightwatch exceptions → fix agents
hive fix --nightwatch --limit 5             # max 5 exceptions
hive spawn feat/my-feature                  # manual single spawn
hive spawn feat/x --context "description"  # with injected context
hive status                                 # list active worktrees
hive harvest feat/my-feature               # remove worktree
hive pr                                     # commit + push + gh pr create (in worktree)
hive pr --all                               # pr for all worktrees with commits
```

---

## Development Conventions

- **TDD**: write failing Pest test first → implement → refactor → commit
- **Commits**: conventional format (`feat:`, `fix:`, `chore:`, `test:`, `refactor:`)
- **Services**: always `final class`
- **Commands**: never instantiate services directly — use `app(Service::class)` or constructor injection
- **No HTTP calls from Services** except `NightwatchIngester` (the only service allowed to call an external API)

---

## Testing

```bash
./vendor/bin/pest              # all tests
./vendor/bin/pest --filter=HiveDetector   # single service
./vendor/bin/pest tests/Architecture/     # arch tests only
```

---

## Config (.hive.json)

Created by `hive init` in the target Laravel project root:

```json
{
  "project": "my-app",
  "stack": ["laravel", "filament", "pest", "nightwatch", "vite"],
  "main_branch": "main",
  "worktrees_path": ".hive/worktrees"
}
```

Environment variables (in the **target** Laravel project `.env`):

| Variable | Required for |
|---|---|
| `ANTHROPIC_API_KEY` | `plan`, `fix` (laravel/ai fallback only) |
| `NIGHTWATCH_TOKEN` | `fix --nightwatch` |
| `NIGHTWATCH_PROJECT_ID` | `fix --nightwatch` |

Note: If Claude Code CLI is installed and authenticated, `ANTHROPIC_API_KEY` is **not required**.
