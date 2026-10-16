# Tutor Dashboard and End-of-Class Reports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give tutors the mockup dashboard, a "Take the register" entry, a read-only register outside the editing window, and an end-of-class report page with drafts, recommendation chips and an issue flag.

**Architecture:** Read models live in `app/Services` (`TutorReports`, `StudentsToWatch`, `TutorDashboard`), as `AdminDashboard` and `GuardianDashboard` do. Drafts are hidden from every existing reader by narrowing `Lesson::lessonReports()` to submitted rows; only the report page reads drafts. Tutor report access is defined once, in `TutorReports::needingReport()`, and the policy, the picker, the dashboard figure and the week list all use it.

**Tech Stack:** Laravel 12 / PHP 8.4, Inertia v3 + React + TypeScript, Wayfinder, Tailwind, Pest.

**Spec:** `docs/superpowers/specs/2026-10-16-tutor-dashboard-and-reports-design.md`

## Global Constraints

- All dates and times are shown in `config('academy.timezone')`.
- "Last 90 days" = lessons whose `starts_at` is within the 90 days up to now.
- Only person-entered **student** attendance counts as evidence: `attendance_records.recorded_by_id IS NOT NULL` (the no-show job leaves it null). The tutor's own record needs no such filter: check-in sets `recorded_by_id` to the tutor, and punctuality only reads Present/Late records.
- A lesson **needs a tutor report** when: it's the tutor's (`tutor_id`), `starts_at <= now`, not cancelled, no **submitted** tutor report, the tutor's own record isn't Absent or Excused, and at least one attendance record has status Present or Late.
- Students keep the 48-hour report window (`LessonPolicy::REPORT_WINDOW_HOURS`). Tutors have no deadline.
- Rating words: 1 Poor · 2 Below par · 3 OK · 4 Good · 5 Excellent.
- Chip labels, in order: Set a follow-up quiz · Move up a difficulty level · Extra practice on this topic · Speak to a parent · Recap at the start of next class.
- Local DB: `php artisan migrate` only, never fresh or reseed. Stage explicit paths only. Pint (`vendor/bin/pint --dirty --format agent`), `npx vp check --fix <files>` and `npm run types:check` before each commit. Regenerate routes with `php artisan wayfinder:generate --with-form`.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A tutor opening another tutor's lesson** (register view, report, picker by id in the URL) must get 403. Covered by tests in Tasks 2, 3 and 5.
2. **A draft must never reach admins**: the inbox, its counts, the admin dashboard's latest reports and tutor-reported flags. Covered in Task 1.
3. **Lessons where every student record is an automatic absence** must not count as "needing a report", must not lower student attendance, and must not flag students. Covered in Tasks 2, 6 and 7.
4. **A tutor with no lessons at all**, or none this week: the dashboard renders, "Take the register" doesn't 500, and the report page shows "You're all caught up". Covered in Tasks 3, 5 and 7.
5. **Reports submitted before this change** (`submitted_at` set, the new columns null or default) still show and stay editable. Covered in Task 3.

---

### Task 1: Report drafts and new report fields (data layer)

**Files:**
- Create: `database/migrations/2026_10_16_xxxxxx_add_drafts_and_recommendations_to_lesson_reports_table.php` (via `php artisan make:migration`)
- Create: `app/Enums/RecommendedAction.php`
- Modify: `app/Models/LessonReport.php`, `app/Models/Lesson.php:87-90`, `app/Services/AdminDashboard.php:305`
- Test: `tests/Feature/LessonReportDraftsTest.php`

**Interfaces:**
- Produces:
  - Migration: `lesson_reports.submitted_at` nullable; `recommended_actions` JSON nullable; `raises_issue` boolean, default false.
  - `enum RecommendedAction: string` with `FollowUpQuiz = 'follow_up_quiz'`, `MoveUpDifficulty = 'move_up_difficulty'`, `ExtraPractice = 'extra_practice'`, `SpeakToParent = 'speak_to_parent'`, `RecapNextClass = 'recap_next_class'`, plus `label(): string` (labels from Global Constraints) and `static options(): array<int, array{value: string, label: string}>`.
  - `LessonReport`: casts `recommended_actions` → `AsEnumCollection::of(RecommendedAction::class)`, `raises_issue` → bool, `submitted_at` nullable; `#[Scope] submitted(Builder $query)` (whereNotNull `lesson_reports.submitted_at`); `isDraft(): bool`.
  - `Lesson::lessonReports(): HasMany` returns **submitted only**. New `Lesson::reportsIncludingDrafts(): HasMany` returns all.

- [ ] **Step 1: Write the failing tests** in `LessonReportDraftsTest.php`:
  - `it('keeps tutor drafts away from the admin class reports inbox and its counts')`: a lesson where a student attended, plus a tutor draft (`submitted_at` null). `ClassReportInbox::counts($filters)['review']` is 0, and the lesson **is** in the `missing` queue.
  - `it('leaves drafts out of the admin dashboard')`: today's lesson with a tutor draft → `today.0.tutorReported` false; `latestReports` empty.
  - `it('still asks for a report when only a draft exists')`: `ReportsDue::for($tutor)` still lists the lesson.
- [ ] **Step 2: Run them**: `php artisan test --compact tests/Feature/LessonReportDraftsTest.php`. Expected: FAIL (`submitted_at` is NOT NULL).
- [ ] **Step 3: Implement** the migration, enum, casts, scope and the two relations. Change `AdminDashboard::latestReports()` (line 305) to `LessonReport::query()->submitted()`. Run `php artisan migrate`.
- [ ] **Step 4: Run** the new tests plus `tests/Feature/ClassReportInboxTest.php tests/Feature/AdminDashboardTest.php tests/Feature/ReportsDueTest.php tests/Feature/LessonReportTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** (`git add` the migration, enum, the three modified PHP files and the test) with message `feat(reports): drafts and recommendation fields on lesson reports`.

### Task 2: Which lessons a tutor reports on (`TutorReports` + policy)

**Files:**
- Create: `app/Services/TutorReports.php`
- Modify: `app/Policies/LessonPolicy.php` (`submitReport`)
- Test: `tests/Feature/TutorReportsTest.php`; update `tests/Feature/LessonReportPolicyTest.php` (its 48-hour dataset now applies to students only).

**Interfaces:**
- Produces in `App\Services\TutorReports`:
  - `needingReport(User $tutor, ?CarbonImmutable $now = null): Builder<Lesson>`, the rule in Global Constraints, ordered `starts_at` desc.
  - `canReportOn(User $tutor, Lesson $lesson, ?CarbonImmutable $now = null): bool`: true when the lesson is in `needingReport`, or the tutor already has a **submitted** report on it and it isn't cancelled.
  - `defaultLesson(User $tutor, ?CarbonImmutable $now = null): ?Lesson`: the current or most recently ended lesson if it needs a report, otherwise the newest lesson in `needingReport`, otherwise null.
- `LessonPolicy::submitReport`: the lesson's tutor → `TutorReports::canReportOn`. Students are unchanged (48 hours, attended). Everyone else is refused.

- [ ] **Step 1: Write failing tests** in `TutorReportsTest.php`:
  - `it('needs a report for a started class the tutor taught and someone attended, however old')`: a lesson 60 days ago with a Present student → in `needingReport`.
  - `it('does not need a report for an unstarted, cancelled, missed or empty class')`: four lessons: future; cancelled; tutor's record Absent; only no-show absences (records with `recorded_by_id` null, status Absent) → none listed.
  - `it('treats a draft as still needing a report and a submitted report as done')`.
  - `it('never lists another tutor\'s classes')`.
  - `it('defaults to the class that just ended, else the newest outstanding one')`.
  - `it('lets the tutor report on an old class but not another tutor\'s')`: policy via `$tutor->can('submitReport', $lesson)`.
- [ ] **Step 2: Run them**: `php artisan test --compact tests/Feature/TutorReportsTest.php`. Expected: FAIL (class missing).
- [ ] **Step 3: Implement** `TutorReports` and the policy change. Update `LessonReportPolicyTest`: the window dataset uses the attending student; add the tutor case "allowed 60 days later".
- [ ] **Step 4: Run** `tests/Feature/TutorReportsTest.php tests/Feature/LessonReportPolicyTest.php tests/Feature/LessonReportTest.php`. Expected: PASS. (`LessonReportTest` "closes 48 hours after the lesson ends": if it uses the tutor, switch it to the student, since tutors now have no deadline.)
- [ ] **Step 5: Commit**: `feat(reports): tutors report on any class they taught that still needs one`.

### Task 3: Report page backend

**Files:**
- Modify: `app/Http/Controllers/LessonReportController.php`, `app/Http/Requests/LessonReportRequest.php`, `routes/web.php`, `app/Services/ClassReportInbox.php` (`needsAttention`, report row/detail props)
- Test: `tests/Feature/TutorReportPageTest.php`; update `tests/Feature/LessonReportTest.php` where props change.

**Interfaces:**
- Consumes: `TutorReports::needingReport/defaultLesson` (Task 2), `RecommendedAction` and `Lesson::reportsIncludingDrafts()` (Task 1).
- Produces:
  - Route `GET /report` → `LessonReportController@next`, named `reports.next`, tutors only (`role:tutor`). It redirects to `lessons.report.edit` for `defaultLesson`, or renders `lessons/report-caught-up` when null.
  - `edit` props, added to the existing ones:
    - `report` gains `recommendedActions: string[]`, `raisesIssue: bool` and `isDraft: bool`, and reads via `reportsIncludingDrafts()`;
    - for tutors only:
      - `classOptions: array<{id:int,label:string}>`, labelled "Year 5 · Geometry · Mon 12 Oct, 17:30": `needingReport` plus the current lesson, newest first;
      - `recommendationOptions` (`RecommendedAction::options()`);
      - `panel: {date: 'Mon 12 Oct 2026', time: '17:30 – 18:30', tutor: string, attendance: '9 of 10 present' | 'Register not taken'}`.
  - `update`: request field `action` in `draft|submit`. A draft saves with `submitted_at` null and is refused (422) if the report is already submitted. A submit stamps `submitted_at = now`. New fields: `recommended_actions` (array of enum values) and `raises_issue` (boolean, tutors only; students' value is ignored).
  - Validation: a tutor **submit** requires `rating`. The draft rules are lengths only. Students keep the existing rules.
  - `ClassReportInbox::needsAttention()` also returns true for `raises_issue`. Report props gain `raisesIssue` and `recommendedActions` (labels).

- [ ] **Step 1: Write failing tests** in `TutorReportPageTest.php`:
  - `it('saves a private draft and submits it later')`: a draft gives `submitted_at` null and the inbox `review` count 0. A submit with rating 4 sets `submitted_at`, and the lesson shows in `review`.
  - `it('refuses a draft once the report is submitted')` → 422.
  - `it('needs a rating to submit but not to save a draft')`.
  - `it('stores the recommendation chips, the free text and the issue flag')`: `recommended_actions` = `[follow_up_quiz, speak_to_parent]`, and an unknown key → validation error.
  - `it('flags a report that raises an issue for the admin team')`: `detail()` → `tutorReport.needsAttention` true.
  - `it('offers only classes that still need a report, plus the one being viewed')`: `classOptions` labels in order.
  - `it('shows the class panel with attendance')`: "9 of 10 present", and "Register not taken" when only no-show records exist.
  - `it('sends the sidebar link to the default class, or says all caught up')`: a redirect, and `component('lessons/report-caught-up')` when nothing is outstanding.
  - `it('still opens and corrects a report submitted before drafts existed')`: a factory report with old columns only.
  - `it('refuses another tutor\'s report page')` → 403.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** as in Interfaces. Regenerate Wayfinder.
- [ ] **Step 4: Run** `tests/Feature/TutorReportPageTest.php tests/Feature/LessonReportTest.php tests/Feature/ClassReportInboxTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(reports): drafts, recommendations and issue flag on the report page`.

### Task 4: Report page UI

**Files:**
- Modify: `resources/js/pages/lessons/report.tsx`
- Create: `resources/js/pages/lessons/report-caught-up.tsx`
- Modify: `resources/js/pages/admin/lesson-reports/*` (wherever a report is rendered: show a "Raised an issue" badge and the recommendation labels)

**Interfaces:**
- Consumes: Task 3 props.

- [ ] **Step 1: Build the page to the mockup:**
  - small-caps "End-of-class report"; the title "How did the class go?"; the subtitle "Tutors and students can both give feedback and make recommendations after every class.";
  - the class `Select` (tutors only), which navigates to the chosen lesson's `edit` route;
  - the star row with its word; the two textareas with the mockup placeholders ("e.g. The group could name and measure angles confidently." / "e.g. Two students need more practice with reflex angles.");
  - toggle chips (checkbox inputs named `recommended_actions[]`); "Another recommendation" (the `recommendations` field);
  - the "Raise an issue with the academy" checkbox panel (tutors only);
  - Save draft (hidden once submitted) and Submit report, using one `Form` whose buttons set `action`;
  - the "This class" side panel (tutors only), with the eye-icon note.
  - Students see the same form without the picker, panel or issue flag.
- [ ] **Step 2: Build** `report-caught-up.tsx`: "You're all caught up", with a link to the dashboard.
- [ ] **Step 3: Verify**: `npx vp check --fix` on the changed files, `npm run types:check`, `npm run build`. Then, in a browser as a seeded tutor at 1440 and 360 widths: toggling a chip, Save draft and Submit all work, with no console errors.
- [ ] **Step 4: Commit**: `feat(reports): end-of-class report page for tutors`.

### Task 5: Register entry and read-only view

**Files:**
- Modify: `app/Policies/LessonPolicy.php` (new `viewRegister`), `app/Http/Controllers/RegisterController.php`, `routes/web.php`, `resources/js/pages/lessons/register.tsx`, `resources/js/components/register-row.tsx`
- Test: `tests/Feature/TakeTheRegisterTest.php`; update `tests/Feature/RegisterTest.php` ("keeps registers private…").

**Interfaces:**
- Produces:
  - `LessonPolicy::viewRegister(User, Lesson): bool`: admins any non-cancelled lesson; the lesson's tutor any of their non-cancelled lessons. `takeRegister` is unchanged and still guards `update`.
  - `RegisterController::show` authorises `viewRegister` and adds props:
    - `canEdit: bool` (= `can('takeRegister')`);
    - `editingNote: string|null`, either "The register opens at 15:30." or "This register closed on Wed 14 Oct.";
    - `checkIn: {at: '15:56', minutesLate: int|null, canCheckIn: bool}|null` for the lesson's tutor;
    - `lesson.dateLong: 'Tuesday 13 October'`, with the subtitle built as "{dateLong} · scheduled 16:00 – 17:00 · Room 2".
  - Route `GET /register`, `role:tutor`, `RegisterController@next`, named `register.next`. It redirects to today's current or next non-cancelled lesson, else the most recent past one, else to `dashboard` with the toast "You have no classes to take a register for."

- [ ] **Step 1: Write failing tests** in `TakeTheRegisterTest.php`:
  - `it('opens the register for the class running now, then the next one today')`.
  - `it('opens the most recent past class on a day with no classes')`.
  - `it('goes back to the dashboard with a message when the tutor has no classes')`.
  - `it('shows an old register read-only and refuses changes to it')`: `canEdit` false, `editingNote` "This register closed on …", and a PUT → 403.
  - `it('never shows another tutor\'s register')` → 403.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.** In the UI:
  - a "← Dashboard" back link and the new subtitle;
  - for the lesson's tutor, the Tutor card is replaced by the check-in badge ("You checked in 15:56 · on time" / "· N min late"), or a "You haven't checked in" badge with the check-in button when `checkIn.canCheckIn`;
  - when `!canEdit`: the row buttons are disabled, "Mark everyone unmarked as on time", "Add note" and Save are hidden, and `editingNote` shows above the register.
- [ ] **Step 4: Run** `tests/Feature/TakeTheRegisterTest.php tests/Feature/RegisterTest.php tests/Feature/AbsenceAlertTest.php tests/Feature/TutorCheckInTest.php`, then lint, types and build. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(register): take-the-register entry and read-only registers for tutors`.

### Task 6: Students to watch

**Files:**
- Create: `app/Services/StudentsToWatch.php`
- Modify: `app/Enums/Difficulty.php` (add `next(): ?self`)
- Test: `tests/Feature/StudentsToWatchTest.php`

**Interfaces:**
- Produces: `StudentsToWatch::for(User $tutor, ?CarbonImmutable $now = null): array<int, array{id:int, name:string, yearGroup:string|null, reasons:string}>`, at most 5. `reasons` joins the parts with " · ".
  - Attendance parts (person-entered student records in the tutor's lessons, last 90 days):
    - "Absent last class" (+ " · {note}" when that record has a note) when their most recent such record is Absent;
    - "Late twice in 90 days" / "Late {n} times in 90 days" when n ≥ 2;
    - "Absent {n} times in 90 days" when the total n ≥ 2. If "Absent last class" also applies, the two merge into one part: "Absent last class (3 times in 90 days)", followed by the note if there is one.
  - Quiz part: per strand (`quizzes.strand_id`), at least 3 submitted attempts in 90 days with average `percentage()` ≥ 85 → "{Strand} quizzes {avg}% — ready for {next difficulty label}". The next difficulty is `YearGroup::defaultDifficulty()?->next()`; if null, "— ready for harder questions". Only the best strand is shown.
  - Order: students with attendance parts first (by number of absences + lateness, descending), then quiz-only students (by average, descending); ties by name.

- [ ] **Step 1: Write failing tests**:
  - `it('flags a student absent from their last class with the register note')`;
  - `it('flags repeated lateness and absence only from twice upwards')`: 1 late → not listed; 2 → "Late twice in 90 days";
  - `it('ignores automatic no-show absences and anything older than 90 days')`;
  - `it('flags a student doing well in a topic after three quizzes at 85% or more')`: Year 5 at 92% Geometry → "Geometry quizzes 92% — ready for Hard"; two quizzes or 84% → not listed; Year 10 → "ready for harder questions";
  - `it('lists at most five, attendance concerns first, and only the tutor\'s own students')`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement**, with a fixed number of queries regardless of class size (group the attendance counts and quiz averages in SQL).
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit**: `feat(tutor): students to watch`.

### Task 7: Tutor dashboard backend

**Files:**
- Create: `app/Services/TutorDashboard.php`
- Modify: `app/Http/Controllers/DashboardController.php` (the tutor branch: replace `tutorToday()`, keep `fromAcademy()`, stop passing `reportsDue` to tutors)
- Test: `tests/Feature/TutorDashboardTest.php`; update tests that assert the old tutor props (`today`, `upcoming`, tutor `reportsDue` in `LessonReportTest` "offers the report on … the tutor Today screen" and `ReportsDueTest`, which keeps testing the `ReportsDue` service directly).

**Interfaces:**
- Consumes: `TutorReports::needingReport` (Task 2), `StudentsToWatch::for` (Task 6).
- Produces: `TutorDashboard::for(User $tutor, ?CarbonImmutable $now = null): array`, with these props:
  - `header: {date: 'Tuesday 13 October 2026', greeting: 'Good afternoon, Mr Adeyemi', now: '15:56'}`. Morning before 12:00, afternoon before 17:00, evening after.
  - `nextLesson: {id, className, time: '16:00 – 17:00', location, students:int, startsAt: ISO8601, state: 'upcoming'|'in_progress', isToday: bool, canCheckIn: bool, checkedInAt: string|null, minutesLate: int|null} | null`.
  - `stats`:
    - `punctuality: {percent:int|null, onTime:int, attended:int}`;
    - `week: {total:int, done:int, toGo:int}`;
    - `reports: {count:int, oldest: string|null}`, where `oldest` reads "Year 5 · Geometry (Monday)" (day name if within 7 days, else "12 Oct");
    - `studentAttendance: int|null`.
  - `week: array<{id, day: 'Mon'|'Today', time, className, state: 'past'|'today'|'upcoming'|'cancelled', summary: string, report: 'submitted'|'draft'|'needed'|null, canOpenRegister: bool}>`. `summary` is "12 of 12 present · you were on time", "Register not taken", "Starts in 4 minutes", "In progress" or "10 students".
  - `studentsToWatch` (Task 6).
  - The controller passes `fromAcademy` as today.

- [ ] **Step 1: Write failing tests** in `TutorDashboardTest.php`, with time frozen at Tue 2026-10-13 14:56 UTC (15:56 Lagos):
  - `it('greets the tutor and shows the next class with check-in')`;
  - `it('shows the next class as checked in once the tutor has arrived')`;
  - `it('works out punctuality, classes this week, reports to submit and student attendance')` (seed the exact counts and assert the props);
  - `it('lists this week\'s classes Monday to Sunday with their state, summary and report status')`;
  - `it('renders for a tutor with no classes at all')`: `nextLesson` null, all counts zero or null, `week` [].
  - `it('shows only the tutor\'s own classes')`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement**, with no N+1: eager-load per lesson, and count attendance with `withCount` constraints.
- [ ] **Step 4: Run** `tests/Feature/TutorDashboardTest.php tests/Feature/DashboardTest.php tests/Feature/LessonReportTest.php tests/Feature/ReportsDueTest.php tests/Feature/TutorCheckInTest.php`. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(tutor): dashboard read model`.

### Task 8: Tutor dashboard UI and sidebar

**Files:**
- Modify: `resources/js/pages/dashboard/tutor.tsx` (rewrite), `resources/js/components/app-sidebar.tsx:61`

**Interfaces:**
- Consumes: Task 7 props; `reports.next` (Task 3) and `register.next` (Task 5) routes.

- [ ] **Step 1: Sidebar.** The tutor items become Dashboard (`dashboard`), Take the register (`register.next`, icon `list`) and End-of-class report (`reports.next`, icon `report`). No badges.
- [ ] **Step 2: Build the page to the mockup:**
  - the header with "Now HH:MM" (a client-side minute tick is allowed);
  - `FromAcademy` (as today);
  - the brand banner: small caps "NEXT CLASS · STARTS IN N MINUTES", and the accent check-in button (the existing check-in form) or "Open register" with the check-in line;
  - four totals cards: My punctuality · last 90 days, Classes this week, Reports to submit (value in `text-late`), Student attendance · last 90 days;
  - the "This week's classes" card, with today's row highlighted and the actions: Report submitted ✓ link, Finish report, Write report (primary), Open register (outline), Upcoming / Cancelled text;
  - the "Students to watch" card ("From attendance, lateness and quiz results in your classes."), or "Nobody to flag right now.";
  - on mobile, the cards stack.
- [ ] **Step 3: Verify:** lint, types, build. Then, in a browser as a seeded tutor at 1440 and 360 widths: check-in works, the links open the right register or report, and there are no console errors.
- [ ] **Step 4: Run** the full feature set touched by this plan: `php artisan test --compact tests/Feature`. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(tutor): dashboard and sidebar`.
