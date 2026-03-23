# Contributing to Hive

First off — thanks for being here. Hive is built in public by a solo Laravel freelancer, and any help is genuinely appreciated.

## The Spirit

Hive scratches a real itch: running parallel Claude Code agents on Laravel projects without losing context or fighting conflicts. If you have the same itch, you're the right person to contribute.

## Ways to Contribute

**You don't need to write code to contribute.**

- **Open an issue** — "This doesn't work on my setup", "I tried `hive plan` and got this error"
- **Share your workflow** — How do you use Hive? What would make it better?
- **Improve docs** — README, docblocks, command `--help` text
- **Write tests** — We use Pest, TDD is the default
- **Fix bugs** — Check [open issues](https://github.com/ultraviolettes/hive-cli-agent-for-laravel/issues)
- **Build features** — See the roadmap below

## Setup

```bash
git clone https://github.com/ultraviolettes/hive-cli-agent-for-laravel.git
cd hive-cli-agent-for-laravel
composer install
./vendor/bin/pest
```

## Development Workflow

1. Fork the repo
2. Create a branch: `feat/my-feature` or `fix/the-bug`
3. Write a failing Pest test first
4. Make it pass
5. Commit with conventional format: `feat: add X`, `fix: Y`, `test: add Z`
6. Open a PR

## Code Conventions

- Services are `final class`
- No HTTP calls from Services except `NightwatchIngester`
- Commands use `app(Service::class)` for injection
- Every new service needs at least 3 Pest tests
- Run `./vendor/bin/pint` before committing

## Roadmap — Good First Issues

- [ ] `hive log` — daily git digest across all projects
- [ ] `hive plan --linear` — ingest from Linear issues
- [ ] `hive plan --jira` — ingest from Jira
- [ ] Better `hive status` output (time elapsed, test results)
- [ ] Shell completions (zsh, bash, fish)
- [ ] `hive sync` — rebase all active worktrees from main
- [ ] Brew formula

## Questions?

Open an issue or find me on Bluesky [@croustibat.bsky.social](https://bsky.app/profile/croustibat.bsky.social).
