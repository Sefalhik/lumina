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
| `required_status_checks` (**strict**) | the [required checks](#required-checks) must pass, and the branch must be up to date |

## Required checks

**This is the only list.** Other documents link here instead of repeating the names or their
number: a count written in three files was wrong in three files the day a fourth check arrived.

By exact context name — a typo here produces a requirement that is never satisfied, and the PR
hangs forever:

```
PHP — PHPStan + PHPUnit
JS — ESLint + Stylelint + Vitest
E2E — Playwright
Security — Dependency review
```

**`Security — Dependency review` judges what a pull request introduces, not the state of `main`**
(LUMN-65). It fails when the PR brings in a package with a known vulnerability of high severity or
worse; a flaw already present is reported by Dependabot alerts instead. That split is deliberate:
on 2026-10-03 an advisory with no fixed version would have blocked every merge had the gate been a
plain `npm audit`. See [Known vulnerabilities](quality-tooling.md#known-vulnerabilities).

**A check can only be required once its job exists on `main`.** Requiring it earlier leaves every
pull request opened before it waiting for a check its own workflow never runs. Merge the job first,
then edit the ruleset.

**`JIRA Sync` is deliberately excluded.** It is not a quality gate: it only runs on pull-request
events, and it cannot fail by design — a refused transition is a `::warning::` (see LUMN-17).
Requiring it would prove nothing.

## Approvals, strict mode and merge method

**Zero required approvals is not an oversight.** GitHub forbids approving your own pull request, so
on a solo repository requiring even one approval locks the owner out permanently.

**Strict mode means rebasing.** Once a PR is merged, every other open PR becomes out of date and
must be rebased onto `main` before it can be merged. With one or two branches in flight this costs
seconds, and it is what catches a branch that no longer passes against the new base.

At the repository level, **squash is the only merge method** (`allow_merge_commit` and
`allow_rebase_merge` are off), and `delete_branch_on_merge` is on.

**The squash commit takes the pull request title** (`squash_merge_commit_title: PR_TITLE`, set on
2026-10-03). GitHub's default, `COMMIT_OR_PR_TITLE`, uses the title of the *commit* when a pull
request holds only one — which is every Renovate pull request. LUMN-69 was merged that way: the PR
had been renamed `[LUMN-69] …` and the commit on `main` says `Update dependency concurrently to
v10`, with no key and no way to rewrite it. Read the setting with
`gh api repos/Sefalhik/lumina --jq .squash_merge_commit_title`.

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

### Pull requests that carry no key

The rule binds work that has a ticket. Three kinds of pull request reach `main` without one, each on
purpose:

- **Routine Renovate updates** — see [Dependency updates](dependency-updates.md).
- **Session brain dumps**, when no ticket is in flight — see
  [Session brain dumps](../CLAUDE.md#session-brain-dumps).
- **Documentation-only changes**, since 2026-10-07: a `docs:` pull request from a `docs/…` branch.
  A ticket would track nothing there — no behaviour to accept, deploy or roll back.

The third holds only while the pull request changes documentation **and nothing else**.
Documentation that describes a change of behaviour travels with the ticket that changes the
behaviour. One pull request still carries one subject.

**Neither the branch name nor the title may quote a ticket key.** `jira-sync.yml` reads both — see
[Re-keying a pull request means opening a new one](#re-keying-a-pull-request-means-opening-a-new-one) —
and a `docs:` pull request titled after `LUMN-73` would move LUMN-73 to review.

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
