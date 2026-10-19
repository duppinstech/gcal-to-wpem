---
description: Review the current diff (or a given path/branch) against project rules
argument-hint: "[path | branch | PR#]"
---

Review target: $ARGUMENTS (if empty, use `git diff` plus `git diff --staged`).

1. Load every file in `.claude/rules/` that applies to the changed paths.
2. Follow the `wp-code-review` skill checklist.
3. If the diff touches request handlers, `$_GET/$_POST/$_FILES`, uploads, or any file listed as security-sensitive in the Plugin facts (`AGENTS.md`), delegate to the `security-reviewer` agent and merge its findings in.
4. Run `php -l` on every changed PHP file.
5. Report findings ranked High / Medium / Low as `file:line — problem — fix`. Finish with a one-line verdict: ship, ship with fixes, or block.

Do not edit files. This command is review-only.
