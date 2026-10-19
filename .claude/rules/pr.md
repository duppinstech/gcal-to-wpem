# Commits and pull requests

## Branches
`feature/<name>`, `fix/<name>`, `chore/<name>`, `security/<name>`, `perf/<name>`, `docs/<name>`, all from `main`.

## Commits
- Imperative mood, subject 72 characters or less.
- One logical change per commit. Don't bundle unrelated WIP.
- Stage files explicitly (`git add <paths>`). Never `git add -A` while unrelated work is in the tree.
- Never commit a root-level release zip; CI builds it.
- Commits need a real author. Check `git config user.name` and `user.email` before the first commit in a new environment; never commit as `root`.

## PR body
Use `.github/pull_request_template.md`: summary, type, checklist, department review, test notes. Link the PLAN.md item. Draft it with the `pr-description` skill.

## Merge gates
- Lint is clean (CI).
- Security review done for handler, upload, rewrite and updater changes.
- `CHANGELOG.md` has an [Unreleased] entry.
- `/department-review` has run with no `block`, and the sign-off table is in the PR body (checked by the Department review gate workflow; label `skip-department-review` only with Odell's OK).
- Version bump and tag only in a dedicated release commit (see `/deploy`).
