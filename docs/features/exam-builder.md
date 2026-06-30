# Exam Builder — Feature Plan (PROPOSED)

> Status: **proposed** — design captured 2026-06-16, not yet built.
> An agent that assembles the 40-question final exam from the bank, proposes
> variations and new questions, and manages when fresh exam content is released
> back into the practice bank.

## Goal

Automate building the IS final exam (40 questions) with a reviewable checkpoint.
Composition, established by current practice:

- **20 questions** pulled verbatim from the bank, stratified across lectures and
  difficulty levels, weighted toward the most-emphasized concepts.
- **10 variations** of bank questions — genuine conceptual twists (not reshuffles).
- **10 new questions** authored to fill gaps in what the first 30 cover.

## Why the composition is sound (integrity rationale)

Settled in the design conversation; recorded so we don't relitigate it:

- The exam is **closed-book / no materials**. There is no answer key to lean on in
  the room, so reproducing the 20 verbatim answers genuinely requires having
  internalized the bank. At 20-of-~740 (growing to ~870 after lectures 11–12),
  blind rote of all answers is implausible — recall is a fair proxy for mastery.
- **Option order is shuffled** in both the bot and the exam, so positional
  memorization ("the answer is D") doesn't work; the student must recognize the
  correct *statement*.
- Therefore the verbatim half is fair. The **10 variations** are the real
  discriminators (memorized-answer vs understood-concept), so they must be genuine
  content changes — swap which statement is true, change a number, invert to
  "which is NOT" — never just a re-shuffle (the bot already shuffles).

## Decisions locked

| Decision | Choice | Notes |
| --- | --- | --- |
| Criticality source | **Infer from bank density** | No criticality field exists. Agent maps each bank question to a concept using `runtime/lecture_topic_map.md` as the concept vocabulary, counts questions per concept, treats high-count concepts as emphasized → they get exam slots; thin/peripheral concepts don't. |
| Output | **Reviewable Hebrew draft → then export** | Two stages with an approval checkpoint: a markdown draft of all 40 (annotated), then a print-ready exam after approval. |
| Spread (20 across lectures × levels) | **Fully automatic** | Agent decides the distribution each run; user reviews it in the draft and can push back there. |
| New questions → bank | **Added to bank, but exam-first** | The 10 new (and the 10 variations) go into the bank but stay *inactive* until released, so students can't drill them before the exam. See release model below. |
| Difficulty signal | **Live success-rate level, not the `difficulty` column** | The `questions.difficulty` column is legacy/ignored by selection. Level = the bot's success-rate bucket (computed in `bot_functions.php`). |

## Release model — 3 moadim per semester

Each semester runs up to **3 exams** (moed A/B/C; B and C are makeups for students
who didn't sit the previous one). Key constraint:

- The three moadim are the same exam *period* and must be **distinct question
  sets** (a moed-A failer retakes moed B → can't be the same questions). So the
  exam-builder runs once per moed, each producing its own fresh 10+10.
- **Fairness rule:** none of a semester's fresh exam questions (across all three
  moadim) may go live in the bank until the **final** administered moed is done.
  Releasing moed-A content before moed B would let makeup students drill same-cycle
  content that moed-A takers never had.

### Mechanism (replaces the earlier "apply SQL manually after the exam" idea)

- New column `questions.release_status` = `active` (default) / `exam_held`.
- `getQuestion()` gains `... AND release_status = 'active'` so held questions are
  written and printable but **never served** to students.
- Tag held rows with `exam_semester` + `exam_moed` so a release targets the right
  batch.
- The exam-builder inserts its 10 new + 10 variations as `exam_held`, tagged with
  the current semester and moed.
- **Semester-management dashboard (extend the EXISTING admin page):** show moed
  A/B/C status checkboxes. When the operator marks the final administered moed and
  confirms "release exam content," all that semester's `exam_held` rows flip to
  `active` and enter the practice rotation. Release is gated behind an explicit
  human confirm (not a hardcoded "after moed 3"), so a 2-moed semester works too.

## Components to build

1. **Data layer — `tools/exam_candidates.php`**
   Reuses the bot's live success-rate → level logic (from `bot_functions.php`) to
   emit every question with: id, `max_lecture`, computed level (L1–L4), answer
   counts, success rate, text, options, `correctans`, `reportedbad`,
   `release_status`. This is the deterministic pool the agent selects from (mirrors
   how `question-writer` uses `runtime/questions_export.txt`). Probation questions
   (`numofanswers < 5`, no stable level) are flagged so they aren't treated as a
   known difficulty. Excludes `exam_held` rows from the candidate pool.

2. **Schema migration — `migrations/YYYY-MM-DD_exam-release-status.sql`**
   Add `release_status` (default `active`), `exam_semester`, `exam_moed` columns to
   `questions`. Idempotent (`IF NOT EXISTS`). Schema = code → commit it.

3. **`getQuestion()` filter** in `bot_functions.php` — add the
   `release_status = 'active'` guard to all serving queries (and the probation-pool
   query). Verify every code path that pulls a question to serve.

4. **The agent — `.claude/agents/exam-builder.md`** (subagent, modeled on
   `question-writer`). Pipeline:
   - **Select 20**: read `exam_candidates` output, map candidates → concepts,
     weight by density, auto-distribute across lectures × L1–L4, pick concrete
     questions favoring critical concepts, skip high-`reportedbad` ones.
   - **Propose 10 variations**: genuine conceptual twists of bank questions, each
     citing its source question id.
   - **Propose 10 new**: gap analysis over the 30 chosen so far → author new
     questions (reading lecture transcripts per question-writer conventions) for
     emphasized concepts still under-tested.
   - **Emit** a Hebrew review draft to `runtime/exams/exam_<semester>_moed<N>_draft.md`
     with all 40 annotated (id / lecture / level / concept; orig→changed for
     variations; gap rationale for new).
   - Takes inputs: semester, moed number, scope (defaults: all lectures, 20/10/10).

5. **Finalization (after the draft is approved)**
   - Generate the print-ready exam via the existing `exportforexam` pattern (HTML,
     with option-order shuffling).
   - Insert the 10 new + 10 variations into `questions` as `exam_held`, tagged with
     semester + moed (question-writer SQL conventions; question *data* → batch in
     gitignored `migrations/data/`, applied to prod with `--default-character-set=utf8mb4`).
   - Release happens later via the dashboard control (component 6).

6. **Semester-management dashboard control — extend the EXISTING admin page**
   Moed A/B/C checkboxes + "release exam content to bank" confirm action that flips
   the semester's `exam_held` rows to `active`. (Verify the exact admin file and
   how `settings.current_week` is edited today; reuse that page, don't add a new one.)

7. **Docs (per the documentation workflow)**
   - This spec → flip to `built` and fold into `ARCHITECTURE.md` when shipped.
   - SRS `FR-*` entries in `docs/requirements.md` (exam assembly, release gating).
   - ADR in `docs/design.md`: (a) density-based criticality choice and its
     trade-off (reflects what was written a lot, not necessarily what's most
     important); (b) the `release_status` gate + release-after-final-moed rule.

## To verify when building (do NOT assume)

- Exact admin file for the "semester management" page and how `current_week` is set.
- Every `getQuestion()` / serving code path that needs the `release_status` filter
  (including the probation-pool query — see CLAUDE.md "Probation pool").
- Whether `lecture_topic_map.md` covers lectures 11–12 yet (added late in the term).
- Confirm option-shuffling already lives in the `exportforexam` scripts so the print
  export inherits it.

## Suggested build order

1. Schema migration + `getQuestion()` `release_status` filter (the gate — nothing
   else is safe to insert without it).
2. `tools/exam_candidates.php` (the data layer everything selects from).
3. `exam-builder` agent producing the reviewable draft.
4. Finalization: print export + `exam_held` insert.
5. Dashboard release control on the existing admin page.
6. Docs (FR entries, ADR), then flip this spec to `built`.
