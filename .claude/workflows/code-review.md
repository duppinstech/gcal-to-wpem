# Workflow: code review cycle

1. **Scope.** Identify the diff (`git diff main...HEAD` or `gh pr diff <n>`) and the PLAN.md item or issue it claims to address.
2. **Static pass.** `php -l` on changed PHP files; check the compatibility floor.
3. **Checklist pass.** Apply the `wp-code-review` skill hunk by hunk.
4. **Security pass.** Delegate to `security-reviewer` when the diff touches handlers, `$_*` superglobals, files, rewrites or the updater.
5. **Behavior pass.** Does it do what the PR says, and nothing extra? Check tests or smoke notes against `testing-standard.md`.
6. **Department pass.** For a PR that is otherwise ready, run `/department-review <PR#>`.
7. **Report.** Findings High, Medium, Low as `file:line — problem — fix`, then a verdict: ship, ship with fixes, or block.
8. **Re-review.** After fixes, re-check only the changed hunks and the earlier findings.
9. **Merge gate.** Lint clean, no open High findings, no department `block`, CHANGELOG updated. Odell merges.
