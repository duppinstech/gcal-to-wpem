# Workflow: bug fix

1. **Reproduce.** Capture the plugin version, WP and PHP versions, relevant settings, and the exact steps. Note the error or log output.
2. **Locate.** Trace from the entry point (hook, REST route, AJAX action, shortcode, block or template) to the root cause. Don't patch symptoms.
3. **Branch.** `fix/<name>` (or `security/<name>` for vulnerabilities; keep those out of public issues, see SECURITY.md).
4. **Regression test first.** Have `test-writer` write a test that fails now. Without a harness, write down the manual repro.
5. **Fix.** The smallest change that addresses the root cause, within the compatibility floor.
6. **Verify.** The test passes, lint is clean, and the repro no longer reproduces. Check nearby behavior didn't regress.
7. **Review.** Run `/review`; `security-reviewer` when the fix touches handlers, uploads, rewrites or the updater.
8. **Department review.** Run `/department-review`. For a small fix most departments return `n/a` quickly; security, ops and support always matter.
9. **Ship.** CHANGELOG `### Fixed` entry, open the PR. If the bug hurts live sites, propose a patch release through `/deploy`.
10. **Log.** A line in `progress.md`, and in `decisions.md` if the fix changed a design assumption.
