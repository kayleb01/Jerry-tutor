# Phase 5b: Quizzes (recipes, picker, player, marking, quizzes home)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Students with quiz access practise maths:

- a quizzes home with their year's quizzes (mixed, plus one per strand), exam prep (11+ and SATs for Years 5–6; GCSE for Years 10–11) and a Challenge tier for KS3 once unlocked;
- one question per screen, with a number pad for number answers;
- answers saved as they go, and marking on the server when they finish;
- a results page with the correct answers and worked explanations.

No question repeats until the pool is used up.

**Spec:** `docs/architecture/database-schema.md` §5 (`quizzes`, `quiz_attempts`, `quiz_attempt_questions`, `question_exposures`, plus the selection algorithm); `docs/plans/implementation-plan.md` Phase 5; `docs/design/design-brief.md` (calm for learners: no countdown by default, worked explanations, big tap targets).

**Builds on:** 5a (questions, topics and strands), 6b (`quiz.access` middleware, paywall), and `YearGroup::defaultDifficulty()`.

## Global Constraints

- **Recipes are seeded by `QuizCatalogueSeeder`** (idempotent on slug, published), not authored by admins in this phase:
    - **Years 3–9:** "Year N mixed" plus "Year N {Strand}" for each strand, at that year's default difficulty and key stage. They pick questions for that year or for the whole key stage (`year_group` null).
    - **KS3 Challenge:** mixed plus one per strand, difficulty Challenging, any KS3 year.
    - **GCSE** (key stage 4, exam track GCSE, any difficulty): mixed plus one per strand.
    - **Exam prep** (key stage 2, any difficulty): "11+ practice" and "SATs practice".
    - **Size:** 10 questions each, untimed.
- **Which quizzes a student sees:**
    - Years 3–9 see their year's quizzes. Years 10–11 see the GCSE quizzes. Years 5–6 also see 11+ and SATs practice.
    - Years 7–9 see Challenge once they have 3 submitted attempts at their default difficulty scoring 80% or more. Before that it shows as locked, with progress ("1 of 3").
    - Each quiz card shows its title, strand, the number of questions available, the last and best score, and "Continue" if an attempt is in progress. A quiz with no published questions shows "No questions yet" and can't be started.
- **`QuestionPicker::pick(Quiz, User, int $count)`:**
    - The pool is published questions matching the recipe: the topic's key stage; the strand (via topic) or the topic itself; the difficulty; questions for that year or the whole key stage; and the exam track (via `exam_track_question`).
    - It takes unseen questions first (shuffled), then tops up with seen ones, those last answered wrongly first, then the least recently seen.
    - Soft-deleted, draft, in-review and retired questions are never picked.
- **Starting a quiz:**
    - It resumes this student's in-progress attempt for the quiz if there is one.
    - Otherwise it creates the attempt and its questions (positions 1..n) and `max_score` (the sum of marks), upserts the exposures (`times_seen` +1, `last_seen_at`), and adds 1 to `questions.times_served`.
    - With no questions available, it shows the toast "This quiz doesn't have any questions yet."
- **The player** (`GET /quiz-attempts/{attempt}/questions/{position}`):
    - Only the owner can see it, and only while the attempt is in progress; anything else redirects to the results.
    - It sends the stem, image URL, type, options (`id` and `body` only), marks, the position and total, the saved response, and which positions are answered.
    - **It never sends `is_correct`, the numeric answer, the tolerance, the accepted answers or the explanation.**
- **Saving an answer:** `PUT …/questions/{position}` with:
    - `option` (an id belonging to the question) for multiple choice;
    - `options[]` for multi-select;
    - `value` (max 30) for numbers;
    - `text` (max 100) for short answers.

    It saves `response` and `answered_at`, then redirects to the next position, or the same one with `stay`. A response that doesn't fit the type gets 422.

- **Finishing:** `POST /quiz-attempts/{attempt}/submit` marks the attempt with `MarkQuizAttempt`, which:
    - **Multiple choice:** correct if the chosen option is correct.
    - **Multi-select:** correct only if the chosen set equals the set of correct options exactly.
    - **Number:** strips spaces, commas and a leading "+"; accepts "a/b"; correct if within `numeric_tolerance` (plus a small epsilon).
    - **Short answer:** lower-cases, trims, collapses spaces, removes spaces around "=" and a trailing full stop, then compares with the accepted answers normalised the same way.
    - **Unanswered** questions are wrong.
    - It sets `is_correct` and `marks_awarded` per question, then the attempt's score, `submitted_at`, `duration_seconds` and the Submitted status. It updates the exposures' `last_correct` and adds to `questions.times_correct`.
    - Finishing twice changes nothing.
- **Results** (`GET /quiz-attempts/{attempt}`): owner only. It shows the score, a message by percentage band, and each question with the student's answer, the correct answer and the explanation (KaTeX). An in-progress attempt redirects to its first unanswered question.
- **Access:** every student quiz route is behind `auth`, `verified`, `role:student` and `quiz.access`.
- **Student dashboard:** each strand card links to that strand's quiz for the student's year (or to `/quizzes`) and shows the last score in that strand, replacing "Quizzes coming soon".
- **Local DB:** migrate only, explicit staging, Pint, `check:fix` and `types:check`, and the commit trailer.

## Tasks

1. **Schema, enums and models:**
    - `QuizAttemptStatus`;
    - the `quizzes` table (plus `sort_order`), `quiz_attempts`, `quiz_attempt_questions` and `question_exposures`;
    - models, factories, and `QuizCatalogueSeeder` (wired into `DatabaseSeeder`).
    - Tests: the catalogue is created, is idempotent, and has the expected recipes.
2. **`QuestionPicker`.**
    - Tests: the recipe filters (key stage, strand, year or null, difficulty, exam track); unseen first; no repeats until the pool is used up; then wrongly answered and least recent first; unpublished and deleted questions are never picked.
3. **`StartQuizAttempt`, the player endpoints and saving answers.**
    - Tests: resume; positions; exposures and `times_served`; the answer key is absent from the player props; owner-only (403); per-type response validation; no questions.
4. **`MarkQuizAttempt`, finishing and results.**
    - Tests: marking per type, including the edge inputs ("1,000", "3/4", " X = 4 ", the exact multi-select set); score and `max_score`; idempotent finishing; exposures' `last_correct` and `times_correct`; results show answers and explanations; owner-only.
5. **`QuizCatalogue::forStudent` and the quizzes home.**
    - Tests: what each year sees, exam prep, the Challenge unlock rule, "Continue", scores, "No questions yet", and the paywall for students without access.
6. **UI:** the quizzes home, the player (progress bar, MathText, option tiles, multi-select checkboxes, the number pad, short-answer input, Previous/Next/Finish with an unanswered warning), results, and dashboard strand cards. Screenshots at 1440 and 360.

## Review Focus

1. The answer key never reaches the client before submission (player props, saved-response echo, error payloads).
2. A student can't read, answer or finish someone else's attempt, or answer after finishing.
3. No repeats until the pool is used up, and the top-up order is right.
4. Marking is robust to how children type answers, and never crashes on odd input.
