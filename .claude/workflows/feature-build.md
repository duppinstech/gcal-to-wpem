# Workflow: feature build

1. **Intent check.** Confirm the feature serves a goal in INTENT.md and decide core vs Pro (if there is a Pro add-on). Add or find the PLAN.md item and mark it `[~]`.
2. **Research (optional).** Use the `research` agent for unfamiliar APIs.
3. **Branch.** `git checkout -b feature/<name>` from an up-to-date `main`.
4. **Scaffold.** Run `/scaffold` if the feature needs new files.
5. **Implement.** Follow `.claude/rules/`. Hooks lint every PHP edit and block vendor and secret writes.
6. **Tests.** Have the `test-writer` agent add coverage, or record manual smoke steps per `testing-standard.md`.
7. **Review.** Run `/review`. `security-reviewer` runs for sensitive areas. Fix every High finding.
8. **Docs.** CHANGELOG [Unreleased] entry, README if behavior changed, `decisions.md` if architecture changed.
9. **Department review.** Run `/department-review`. Resolve every `block` and apply the agreed fixes, then re-review.
10. **PR.** Draft with the `pr-description` skill, include the department sign-off table, open it once Odell approves.
11. **Close out.** Mark PLAN.md `[x]` and add a line to `progress.md`.
