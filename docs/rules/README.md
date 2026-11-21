# Rules

The documentation has three tiers (LUMN-150), after Claude Code's own model:

| Tier | Loaded | Holds |
|---|---|---|
| `CLAUDE.md` | in every session, whole | the map, and what holds everywhere |
| a rule of this directory | when a file its `paths:` frontmatter names is read or written | what holds for one part of the code, in the imperative |
| the references a rule links to | when needed | the reason for a rule, its real case, the history, the details |

A rule loads by itself: nobody has to remember to read it. That is what the table of `CLAUDE.md`
could not do — on 2026-11-21 a test was changed without the testing conventions being read, though
the table asked for it. A rule also costs what it weighs each time a file of its domain is opened:
it stays short, and its reasons live in the references.

| Rule | Loaded when touching |
|---|---|
| [backend.md](backend.md) | `app/`, `routes/`, `bootstrap/`, `config/`, `database/` |
| [frontend.md](frontend.md) | `resources/js/`, `resources/views/`, `resources/css/` |
| [translations.md](translations.md) | `lang/`, the islands' JSON, the translation services and their configuration |
| [tests.md](tests.md) | `tests/`, every `__tests__/` directory, the test runners' configuration |
| [deployment.md](deployment.md) | the deployment scripts and workflow, `public/index.php`, `.env.example` |
| [dependencies.md](dependencies.md) | `renovate.json5`, `composer.json`, `package.json`, the Node and npm settings |
| [quality.md](quality.md) | the linters, PHPStan, the audit script, the CI workflows |

## How a rule is bound

Each rule is a document of this directory, exposed to Claude Code by a **symbolic link** in
`.claude/rules/` — never a copy, which would drift from what is read here. Its frontmatter has one
form only:

```markdown
---
paths:
  - "app/**"
  - "routes/**"
---
```

`scripts/claude-rules.js`, run by the suite, refuses a copy, a broken or misdirected link, a rule
without its link, any other frontmatter — Claude Code ignores one it cannot read, and the rule then
loads in every session — and a pattern that names no file git tracks, which would never load.

To see what loads: `/memory` lists the instruction files of the session, `/context` their weight. To
prove it without a session of your own, a headless run with an `InstructionsLoaded` hook logs every
load, with its reason and the file that triggered it — how it was checked on 2026-11-21:

```bash
claude -p "Read tests/TestCase.php, then reply DONE." --model haiku --allowedTools Read \
  --settings '{"hooks":{"InstructionsLoaded":[{"hooks":[{"type":"command","command":"cat >> /tmp/loads.jsonl"}]}]}}'
```

Reading `tests/TestCase.php` loaded `docs/rules/tests.md` (`path_glob_match`); a file of `app/` loaded
`docs/rules/backend.md`; `docs/git-workflow.md` loaded no rule. Claude Code follows the link and
reports its target.

## What it costs

Measured on 2026-11-21, in bytes — about 4 bytes a token. "Before" counts what the table of
`CLAUDE.md` asked to read before working on the domain, whole; "after", the rule that loads by itself
and the part of the reference the work needs.

| Session | Before LUMN-150 | After |
|---|---|---|
| no file of a domain read — a ticket, a plan, the documentation | 12 506 | 7 666 |
| on a test | 50 224 (`CLAUDE.md` + `testing-conventions.md`) | 23 155 (`CLAUDE.md` + rule + `testing/doctrine.md`) |
| on a deployment | 81 834 (`CLAUDE.md` + `deployment.md`) | 22 274 (`CLAUDE.md` + rule + `deployment/deploying.md`) |

The rule alone, without opening its reference, costs 1.1 to 2.5 KB.

## Adding a rule

- It holds everywhere: in `CLAUDE.md`, which stays under 200 lines.
- It holds for one part of the code: in the rule of that domain, in the imperative, with a link to
  its reason. A new domain gets its document here, its frontmatter, its link
  (`ln -s ../../docs/rules/<name>.md .claude/rules/<name>.md`) and its row above.
- No document of `docs/`, rules included, exceeds 15 KB: `DocumentationIndexTest` says so.

The design comes from myFormasup (FORMASUP-2383), where it was proven first.
