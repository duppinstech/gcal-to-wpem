---
description: Run every department reviewer (security, legal, finance, product, marketing, support, ops, UX) on the finished change, triage their suggestions into PLAN.md, and write timestamped review notes
argument-hint: "[path | branch | PR# | vX.Y.Z (release)] [--only a,b] [--skip a,b]"
---

Review target: $ARGUMENTS
- Empty: the current branch against main, `git diff main...HEAD` plus uncommitted changes.
- `vX.Y.Z`: release mode, `git diff <previous tag>...HEAD`.
- `--only` / `--skip`: limit the departments. Keys: security, legal, finance, product, marketing, support, ops, ux.
- Departments listed under "Departments skipped" in the Plugin facts (`AGENTS.md`) are skipped by default.

Run this **after development is done** (code written, lint clean, tests or smoke notes recorded) and **before** the PR is opened or a release is cut. It changes no code. The only files it writes are PLAN.md and new files in `.claude/reviews/`.

## 1. Build the brief
Collect the diff summary (`git diff --stat`), the changed files, and the PLAN.md item or goal the change serves. Every agent gets the same brief: the goal, the review target command, the file list, and "return findings only; do not edit files". Ask each agent to read PLAN.md (mark known items "already in PLAN" instead of re-reporting them) and to tag every finding:
- `[auto]` or `[needs Odell]` (see the triage rules in step 4)
- a priority: `[P0]` before the next release, `[P1]` soon, `[P2]` later

## 2. Fan out in parallel
Launch all of these in **one message** so they run concurrently:

| Key | Agent | Department |
|---|---|---|
| security | `security-reviewer` | Security |
| legal | `legal-reviewer` | Legal and licensing |
| finance | `finance-reviewer` | Finance and pricing |
| product | `product-reviewer` | Product |
| marketing | `marketing-reviewer` | Marketing |
| support | `support-docs-reviewer` | Support and docs |
| ops | `release-ops-reviewer` | Operations and release |
| ux | `ux-reviewer` | UX, accessibility, performance |

An agent with nothing in scope returns `VERDICT: n/a`, which is cheap. (Tools without parallel subagents: run each reviewer's instructions in turn and keep their outputs separate.)

## 3. Consolidate
Produce one report:

**Department sign-off**

| Department | Verdict | Top finding |
|---|---|---|
| Security | ok / fix-before-merge / block / n/a | … |

Then:
- **Blockers:** every `block` and High finding, as `file:line — problem — fix (department)`.
- **Action items:** de-duplicated across departments, grouped by file. When departments disagree, show both views and recommend one.
- **Docs and legal text to update:** merged from legal, support and marketing.
- **Pricing and cost note:** from finance, one or two lines.
- **PLAN.md updates:** from product.
- **Release-note draft:** from marketing.
- **Smoke test:** from ops.

## 4. Triage into PLAN.md
Department suggestions are valid by default. Respect them unless there's a concrete reason not to, and record that reason.
- **Approved → PLAN.** Routine work within the existing rules: bug and security fixes, escaping, compatibility, docs, CHANGELOG, help text, i18n, tests, caching, `.distignore`/uninstall coverage, credits files, accessibility and performance fixes that don't change the design. Add it to PLAN.md at the suggested priority with a `(<dept>, <review date>)` source tag. No need to ask.
- **Forwarded → Odell.** Anything that needs the owner's say: prices or packaging, free vs Pro, spending money, legal positions or "Attorney review", product direction or scope, removing features or deleting data, anything published externally, changing the updater, visual redesigns, or waiving a High finding. Add it under **Decisions needed (Odell)** in PLAN.md as a question with the department's recommendation. Don't act on it until Odell answers.
- **Already tracked.** Don't duplicate it. Adjust the existing item if a department gave a good reason.
- **Declined.** Only for a false positive, a duplicate, or out of scope, always with a written reason.
- Merge duplicates across departments into one PLAN item that credits every source.
- **Approved items are open to work right away** (Odell's standing rule). After the notes are written, start on them in priority order using the normal workflows. When the batch is done, run `/department-review` on it again (the re-review). Forwarded items wait for Odell.

## 5. Write the review notes
Create `.claude/reviews/YYYY-MM-DD-HHMM-<topic>.md` (local time from `date`) following the template in `.claude/reviews/README.md`: attendance and verdicts, highlights, a **summary** of each department's findings with each item's outcome (Approved → PLAN, Forwarded → Odell, Already tracked, Declined plus the reason). Summarize; don't paste full reports. Text that's ready to use (CHANGELOG entries, help text, legal or README wording, smoke tests) goes in a matching `…-appendix.md`, linked from the PLAN items. If the repo is public: no exploit detail for open security issues and no private numbers.

## 6. Gate
- Any `block`: the change does not merge or ship. Say so plainly.
- `fix-before-merge`: the fixes are now approved P0 items in PLAN.md. Start on them.
- High findings from security or legal can only be waived by Odell, explicitly. Record each waiver in `.claude/memory/decisions.md` and in the next review's notes.
- "Attorney review" (legal) or "revisit pricing" (finance) goes to Odell as a decision. It doesn't block merging unless Odell says so.

Paste the sign-off table into the PR body under **Department review** (the PR gate workflow checks for it), and link the review notes file.
