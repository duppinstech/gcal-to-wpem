---
name: pr-description
description: Draft a pull request title and body from the branch diff, following .github/pull_request_template.md. Use when opening or updating a PR.
---

# PR description

1. Gather: `git log main..HEAD --oneline`, `git diff main...HEAD --stat`, and the related PLAN.md item.
2. Title: imperative, 72 characters or less, matching commit style.
3. Body follows `.github/pull_request_template.md`:
   - **Summary**: 2–4 sentences on what changed and *why*, for a reviewer who hasn't seen the code.
   - **Type**: tick one.
   - **Checklist**: tick only what was actually done; leave the rest unticked and say why.
   - **Department review**: paste the sign-off table from `/department-review` and link the notes in `.claude/reviews/`.
   - **Test notes**: exact steps run and their results.
4. Call out breaking changes (renamed hooks, options or meta keys) prominently.
5. End with the attribution line required by the session, if any.
6. Create it with `gh pr create --title "..." --body-file <tmp>`, and only when asked.
