# Department review notes

Every `/department-review` run is a meeting. Each gets a timestamped file here recording what each department found, what it recommended, and what was decided: which items went into PLAN.md, which went to Odell, and which were declined. Decisions only; implementation progress lives in PLAN.md and `.claude/memory/progress.md`.

These files live in `.claude/` so they're excluded from the release zip with the rest of the AI tooling. They are still committed, so if the repo is public, they are public.

## File naming
`YYYY-MM-DD-HHMM-<topic>.md`, local time. Example: `2026-10-14-1951-baseline-review.md`.
Re-reviews use the same topic with `-rereview` (then `-round3`, …). An optional `…-appendix.md` keeps ready-to-use text the departments drafted (CHANGELOG entries, help text, legal wording).
Files are append-only history. Don't rewrite past reviews. When Odell decides a forwarded item later, note it in the next review's file (or in `.claude/memory/decisions.md`) and link back.

## Decision outcomes
| Outcome | Meaning |
|---|---|
| **Approved → PLAN** | Routine item within the existing rules. Added to PLAN.md and **open to work right away** (standing rule below). |
| **Forwarded → Odell** | Needs Odell's decision: pricing or packaging, spending, a legal position or attorney review, product direction or scope, anything published externally, deleting features or data, changing the updater, or waiving a High finding. Listed in PLAN.md under "Decisions needed". |
| **Already tracked** | Already in PLAN.md; may have been reprioritized. |
| **Declined** | Not acted on, always with a reason (out of scope, duplicate, false positive). |

## Standing rule: approved items are open to work
_Set by Odell. Applies to every review._
- Once a routine item is **Approved → PLAN**, work on it straight away in priority order (P0 first), using the normal workflows, then run `/department-review` again on the batch.
- Only **Forwarded → Odell** items wait for him. His answer turns the item into approved work.
- Safety boundaries still apply: ask before pushing to `main`, tagging or releasing, deleting files, or rewriting history. Releases go through `/deploy`.

## Public-repo rule
If this repo is public, notes summarize; they don't publish exploits. An open High security finding is recorded as "security issue in `<area>`, tracked privately" until fixed. Private numbers from `CLAUDE.local.md` never appear here.

## Template
```markdown
# Department review: <topic>
**When:** YYYY-MM-DD HH:MM <TZ> · **Scope:** <branch / diff / release> · **Chair:** main session · **Owner:** Odell Duppins

## Attendance and verdicts
| Department | Verdict | Findings | Summary |
|---|---|---|---|

## Highlights
- 3–5 bullets: what Odell most needs to know.

## Findings and recommendations by department
### <Department>
- <finding summary> → <recommendation>. **Outcome:** Approved → PLAN (P#) | Forwarded → Odell | Already tracked | Declined (reason)

## Decisions
- **Approved into PLAN.md:** list
- **Forwarded to Odell:** list, phrased as questions
- **Declined:** list with reasons
```
