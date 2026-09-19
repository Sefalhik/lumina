# Branch protection and merge policy

Set up 2026-09-12. `main` is governed by a **single ruleset**, `Protection de main`
(`id 23077780`), active, with **no bypass actors** — it applies to administrators, including the
repository owner.

| Rule | Effect |
|------|--------|
| `deletion` | `main` cannot be deleted |
| `non_fast_forward` | no force-push |
| `required_linear_history` | no merge commits |
| `pull_request` | direct pushes blocked; **0 required approvals**; squash is the only allowed merge method |
| `required_status_checks` (**strict**) | the three quality checks must pass, and the branch must be up to date |

Required checks, by exact context name — a typo here produces a requirement that is never
satisfied, and the PR hangs forever:

```
PHP — PHPStan + PHPUnit
JS — ESLint + Stylelint + Vitest
E2E — Playwright
```

**`JIRA Sync` is deliberately excluded.** It is not a quality gate: it only runs on pull-request
events, and it cannot fail by design — a refused transition is a `::warning::` (see LUMN-17).
Requiring it would prove nothing.

**Zero required approvals is not an oversight.** GitHub forbids approving your own pull request, so
on a solo repository requiring even one approval locks the owner out permanently.

**Strict mode means rebasing.** Once a PR is merged, every other open PR becomes out of date and
must be rebased onto `main` before it can be merged. With one or two branches in flight this costs
seconds, and it is what catches a branch that no longer passes against the new base.

At the repository level, **squash is the only merge method** (`allow_merge_commit` and
`allow_rebase_merge` are off), and `delete_branch_on_merge` is on.

## One intention, one ticket, one PR, one commit

`main` receives exactly one squashed commit per pull request, and each carries the JIRA key of **one
intention**. Several commits inside a branch are fine — the squash collapses them. What breaks the
rule is several *pull requests* sharing one key.

When a ticket turns out to span more than one intention, it is not the rule that bends: the ticket is
at the wrong level. `Epic` exists in this project and `LUMN-3` to `LUMN-7` already use it. Convert
the parent and give each intention its own child rather than repeating a key across commits — which
is also what makes decommissioning work, since the epic then enumerates exactly what to revert.

Amending a commit already on `main` is not an option here and never will be: the ruleset carries
`non_fast_forward` and `pull_request`, with no bypass actor.

## Re-keying a pull request means opening a new one

`jira-sync.yml` extracts the key from the **branch name first**, the title second:

```bash
KEY=$(printf '%s\n%s' "$HEAD_REF" "$PR_TITLE" | grep -oiE 'LUMN-[0-9]+' | head -1 | ...)
```

So changing the title alone leaves the synchronisation aimed at the old ticket.

Renaming the branch does not fix it either, and this was measured on 2026-09-14 rather than assumed.
`POST /repos/{owner}/{repo}/branches/{branch}/rename` moved the branch and fired a `push` event —
**no `pull_request` event** — and GitHub then *closed* the pull request while keeping
`head.ref` pointing at a branch that no longer existed. Merging it would have transitioned the epic.

**Rename the branch, then open a fresh pull request from it.** The commits are untouched; only the
pull request number changes. Close the old one with a comment saying why, so the history explains
itself.

## Checking protection — do not use the classic endpoint

```bash
gh api repos/Sefalhik/lumina/rules/branches/main   # effective rules, with their ruleset_id
gh api repos/Sefalhik/lumina/rulesets              # existing rulesets
gh api repos/Sefalhik/lumina/rulesets/<id>         # detail, including bypass_actors
```

`GET /repos/{owner}/{repo}/branches/main/protection` reports **classic branch protection only** and
is blind to rulesets. On this repository it answers `404 Branch not protected` while the branch is
in fact protected — a false negative that has already produced one wrong diagnosis.

Always inspect `bypass_actors` as well: a ruleset that can be bypassed is a reminder, not a
protection. The previous ruleset had one in `always` mode, which is why it did not apply to the
owner.
