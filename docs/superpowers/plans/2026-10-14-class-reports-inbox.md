# Class Reports Inbox

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the admin End-of-class reports page into a review inbox. It keeps the approved design (recent classes on the left, the class's tutor and student reports on the right) but still works when there are hundreds of reports. "Raise with tutor" sends the tutor a note they acknowledge on their dashboard.

**Architecture:**

- **Granularity:** the inbox works per *lesson*, not per report. One row is one class session.
- **Review state:**
  - `lessons.reports_reviewed_at` and `reports_reviewed_by_id` record when an admin marked a lesson's reports as reviewed.
  - A lesson needs review while any of its reports was submitted after that time, or it was never reviewed. So an edited or late student report puts the lesson back in the queue.
- **Raising with the tutor:** a `raised_issues` table holds `lesson_id, tutor_id, raised_by_id, note, acknowledged_at`.
- **Queue logic:** `App\Services\ClassReportInbox` owns the queues, filters, counts, the list row and the detail payload, with one query builder per queue.
- **Admin page:** a sticky list pane loads more with Inertia v3 `Inertia::scroll()` and a manual "Load more". The selected lesson is in the URL (`?lesson=`) and is loaded as its own prop. "Mark as reviewed" moves to the next class in the list.
- **Tutor side:** a "From the academy" card on the tutor dashboard with a "Got it" button.

**Tech Stack:** Laravel 13, Inertia v3 (scroll props), React 19, Tailwind v4, Wayfinder, Pest 5.

**Spec:**
- **Design:** the user's "End-of-class reports" screen (tabs and badges, recent classes list, tutor report card with "What went well", "To improve" and "Recommendation", student reports "Shown to admin only", and the "Raise with tutor" and "Mark as reviewed" buttons).
- **User answers on 2026-10-14:**
  - "Review inbox" layout.
  - "Raise with tutor" = flag plus a note shown on the tutor's dashboard until acknowledged, with no email.
- **Reporting rules:** Phase 4 plan (`2026-10-13-phase-4-reports.md`). Admins read every report, and the reporting window closes 48 hours after the end.

## Global Constraints

- **Queues** (tab order; default `review`):
  - `review`, "Needs review": the lesson has a report submitted after `reports_reviewed_at`, or one that has never been reviewed.
  - `raised`, "Raised with tutor": the lesson has an unacknowledged raised issue.
  - `missing`, "Awaiting tutor report": the lesson has ended, attendance was taken (it happened), and there is no tutor report.
  - `reviewed`, "Reviewed": `reports_reviewed_at` is set and no report is newer.
  - `all`, "All": the lesson has any report or any attendance.
  - Every queue excludes cancelled lessons and lessons that haven't started yet.
- **Filters:**
  - `search` matches the class name, the tutor's name or any report author's name, case-insensitively, with LIKE escaped.
  - `class`, `tutor`, and `from`/`to` (academy dates on `starts_at`).
  - Unknown or malformed values are ignored.
  - The counts on the tabs respect every filter except the queue itself.
- **Row status** (first match wins):
  1. Raised with tutor.
  2. Tutor report pending (ended, no tutor report, within 48 hours) or Tutor report missing (after that).
  3. Reviewed.
  4. Both sides submitted.
  5. Tutor report only.
  6. "N student reports".
  7. No reports yet.
- **Order:** newest lesson first (`starts_at` desc, then `id` desc). 25 rows per page.
- **Detail:**
  - The tutor report comes first.
  - Student reports are sorted with *needs attention* first (any `issues` text, or a rating ≤ 2), then newest.
  - The summary shows the count, the average rating (1 decimal place) and the needs-attention count.
  - It also shows the raised issues with their acknowledgement state, and who reviewed the lesson and when.
- **Mark as reviewed:** sets the review time and admin, then redirects to the inbox with the same filters and `lesson` set to the `next` id the page sends, or none.
- **Raise with tutor:** `note` is required and at most 1000 characters. It creates an issue for `lesson.tutor_id` and also marks the lesson reviewed (raising it is the admin's action on it). It stays on that lesson.
- **Tutors:**
  - A tutor sees and acknowledges only their own issues (anyone else gets a 403).
  - Acknowledging is idempotent.
  - Acknowledged issues leave the dashboard and the `raised` queue, and the admin detail shows "Read by the tutor {date}".
- **Sidebar badge:** the admin sidebar shows the Needs review count, with no filters, on "Class reports". It is hidden at 0.
- **Times:** shown in the academy timezone. Day labels are "Today", "Yesterday", "Mon 12 Oct", with the year added when it isn't the current year.
- **Local DB:** `php artisan migrate` only, never `migrate:fresh`. Stage explicit paths. Run Pint, `npm run check:fix` and `npm run types:check` before each commit. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A report submitted after a review re-opens the lesson.** A student report edited after "Mark as reviewed" puts the lesson back in Needs review. → Task 2.
2. **Queues at the edges:**
   - A cancelled lesson is never listed.
   - A future lesson is never listed.
   - A lesson that ended without attendance isn't "awaiting tutor report".
   - A lesson inside its 48-hour window shows "pending", not "missing".
   → Task 2.
3. **Raised issues are private to their tutor.** Another tutor can't acknowledge them (403), and a tutor's dashboard lists only their own. → Task 4.
4. **The next-class redirect keeps the admin's filters and ignores anything else in the referrer.** → Task 3.
5. **The list stays cheap at scale.** No N+1 per row (eager loads), and counts are one query per queue. → Task 2/3 (check the query count in review).

---

### Task 1: Schema for review state and raised issues

**Files:**
- Create:
  - `database/migrations/<ts>_add_report_review_to_lessons_table.php`
  - `database/migrations/<ts>_create_raised_issues_table.php`
  - `app/Models/RaisedIssue.php`
  - `database/factories/RaisedIssueFactory.php`
- Modify: `app/Models/Lesson.php` (fillable/casts for `reports_reviewed_at`, `reports_reviewed_by_id`; `raisedIssues()`, `reportsReviewedBy()`)
- Test: `tests/Feature/ClassReportInboxTest.php` (started here, extended in Task 2)

**Interfaces (Produces):**
- **`Lesson`:** `reports_reviewed_at: ?CarbonImmutable`, `reports_reviewed_by_id: ?int`, `raisedIssues(): HasMany`, `reportsReviewedBy(): BelongsTo` (withTrashed).
- **`RaisedIssue`:**
  - Fields: `lesson_id`, `tutor_id`, `raised_by_id`, `note`, `acknowledged_at: ?CarbonImmutable`.
  - Relations: `lesson()`, `tutor()` and `raisedBy()` (both withTrashed).
  - Factory: the default is an open issue on `Lesson::factory()`, with `tutor_id` taken from the lesson and `raised_by_id` from an admin factory. State `acknowledged()`.

Migrations:

```php
// add_report_review_to_lessons_table
Schema::table('lessons', function (Blueprint $table) {
    $table->dateTime('reports_reviewed_at')->nullable();
    $table->foreignId('reports_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
});
// down: dropConstrainedForeignId('reports_reviewed_by_id'); dropColumn('reports_reviewed_at')

// create_raised_issues_table
Schema::create('raised_issues', function (Blueprint $table) {
    $table->id();
    $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tutor_id')->constrained('users');
    $table->foreignId('raised_by_id')->nullable()->constrained('users')->nullOnDelete();
    $table->text('note');
    $table->dateTime('acknowledged_at')->nullable();
    $table->timestamps();
    $table->index(['tutor_id', 'acknowledged_at']);
});
```

- [ ] Step 1: Write a failing test in `ClassReportInboxTest.php`: `it('records reviews and raised issues on a lesson')`.
  - Create a lesson.
  - Set `reports_reviewed_at` and `reports_reviewed_by_id`.
  - Create `RaisedIssue::factory()->for($lesson)`.
  - Assert the casts (CarbonImmutable), `raisedIssues` count 1, `reportsReviewedBy` is the admin, and the issue's `tutor_id === $lesson->tutor_id`.
- [ ] Step 2: Run it, and expect a FAIL (no column or class).
- [ ] Step 3: Write the migrations, the model, the factory and the `Lesson` changes. Run `php artisan migrate`.
- [ ] Step 4: Run it, and expect a PASS. Commit: "feat: review state and raised issues for class reports".

---

### Task 2: ClassReportInbox service

**Files:** Create `app/Services/ClassReportInbox.php`. Test: `tests/Feature/ClassReportInboxTest.php`.

**Interfaces (Produces):**
- `QUEUES` (array of key ⇒ label, in tab order).
- `filters(array $input): array{queue: string, search: string, class: ?int, tutor: ?int, from: ?CarbonImmutable, to: ?CarbonImmutable}`
- `query(array $filters, ?string $queue = null, ?CarbonImmutable $now = null): Builder<Lesson>`
- `counts(array $filters): array<string, int>`
- `paginate(array $filters, int $perPage = 25): LengthAwarePaginator` (rows)
- `row(Lesson, CarbonImmutable $now): array{id, className, tutor, day, time, state, stateLabel}`
- `detail(Lesson, ?CarbonImmutable $now = null): array` with these keys:
  - `id, className, tutor, when, attendance, state, stateLabel`;
  - `tutorReport` (a Report or null), `studentReports: Report[]`;
  - `studentSummary{count, averageRating, needsAttention}`;
  - `raisedIssues[{id, note, raisedBy, raisedAt, acknowledgedAt}]`;
  - `reviewed{at, by}` or null.
  - A Report has `id, author, rating, wentWell, issues, recommendations, submittedAt, needsAttention`.
- `isReviewed(Lesson): bool`

Implementation notes (write them exactly like this):
- **Base query:** `Lesson::query()->where('status', '!=', LessonStatus::Cancelled)->where('starts_at', '<=', $now)`, plus the filters.
  - **Dates:** `from`/`to` compare `starts_at` against `$from->startOfDay()->utc()` and `$to->endOfDay()->utc()`, the same as the old page.
  - **Search:** `where(fn => whereHas('classGroup', LIKE) ->orWhereHas('tutor', LIKE) ->orWhereHas('lessonReports.author', LIKE))`, using `LOWER(name) LIKE ? ESCAPE '!'` with `!`, `%` and `_` escaped.
- **The "unreviewed report" subquery is shared** by `review` and `reviewed`:
  `fn (Builder $reports) => $reports->where(fn (Builder $q) => $q->whereNull('lessons.reports_reviewed_at')->orWhereColumn('lesson_reports.submitted_at', '>', 'lessons.reports_reviewed_at'))`
- **Queues:**
  - `review`: `whereHas('lessonReports', unreviewed)`.
  - `raised`: `whereHas('raisedIssues', whereNull acknowledged_at)`.
  - `missing`: `where('ends_at', '<', $now)->whereHas('attendanceRecords')->whereDoesntHave('lessonReports', author_role = tutor)`.
  - `reviewed`: `whereNotNull('reports_reviewed_at')->whereDoesntHave('lessonReports', unreviewed)`.
  - `all`: `where(fn => whereHas('lessonReports')->orWhereHas('attendanceRecords'))`.
- **`paginate()`:** eager-loads `classGroup`, `tutor`, `lessonReports` (`id, lesson_id, author_role, submitted_at`) and `raisedIssues` (unacknowledged only), then orders and paginates with `withQueryString()` and `through(row)`.
- **The 48-hour window:** reuse the window from `LessonPolicy::submitReport` by adding `public const REPORT_WINDOW_HOURS = 48` there and using it in both places.

- [ ] Step 1: Write failing tests (Pest, `travelTo('2026-10-14 12:00 UTC')`, academy tz Africa/Lagos). Use helpers `lessonAt(string $start, array $attrs = [])`, `attend(Lesson)` (one student attendance record via `RecordAttendance`) and `report(Lesson, role, attrs)`:
  1. **Queue membership.** Build 6 lessons:
     - (a) a student report, never reviewed;
     - (b) reviewed after its reports;
     - (c) attendance, ended, no tutor report;
     - (d) an open raised issue;
     - (e) a cancelled lesson with a report;
     - (f) a future lesson with attendance.
     Expect: review = [a, d if it has a report]. Make d have no reports, so review = [a]. raised = [d]. missing = [c, plus a if a has attendance; give a none]. reviewed = [b]. all = [a, b, c, d]. e and f are never listed.
  2. **A report edited after the review re-opens it.** Review (b) at 10:00. Then `submitted_at = 11:00` on a new student report. (b) is now in review, not reviewed.
  3. **Filters and counts.**
     - `class`, `tutor` and `from`/`to` narrow the list.
     - `search` matches the class name, the tutor's name and a student author's name, and `%` is treated literally.
     - `counts()` returns all five keys, honours the filters, and ignores the queue.
     - `filters(['queue' => 'nope', 'class' => 'abc', 'from' => 'yesterday'])` gives `queue = review` and nulls.
  4. **Row states.** Check the label for each case: raised; ended 2 hours ago with no tutor report → "Tutor report pending"; ended 3 days ago → "Tutor report missing"; reviewed; tutor and student reports → "Both sides submitted"; tutor only → "Tutor report only"; 2 students → "2 student reports"; nothing yet → "No reports yet". Check `day` for today → "Today" and for yesterday → "Yesterday".
  5. **Detail.**
     - The tutor report is separate.
     - Student reports are ordered: one with issues text first, a rating-1 report next, then the newest.
     - `studentSummary` is `{count: 3, averageRating: 2.7, needsAttention: 2}`.
     - `attendance` is "1 of 2 present" (one present, one absent).
     - `raisedIssues[0].acknowledgedAt` is null, then a date after acknowledging.
     - `reviewed.by` is the admin's name.
- [ ] Step 2: Run them, and expect a FAIL (no class).
- [ ] Step 3: Implement the service as above.
- [ ] Step 4: Run them, and expect a PASS. Commit: "feat: class report inbox queues, filters and detail".

---

### Task 3: Admin inbox endpoints and the sidebar badge

**Files:**
- Modify:
  - `app/Http/Controllers/Admin/LessonReportController.php` (rewrite `index`; add `review` and `raise`)
  - `routes/web.php`
  - `app/Http/Middleware/HandleInertiaRequests.php` (`badges`)
  - `resources/js/types/global.d.ts` (`badges`)
- Test: rewrite `tests/Feature/Admin/LessonReportListTest.php` for the inbox. Keep its admin-only test. The flat per-report list and its `role` filter are replaced by the approved design.

**Routes** (admin group):
- `PUT lesson-reports/{lesson}/review` → `review`, named `admin.lesson-reports.review`.
- `POST lesson-reports/{lesson}/raise` → `raise`, named `admin.lesson-reports.raise`.

**Index props:**
- `lessons`: `Inertia::scroll(fn () => $inbox->paginate($filters))`.
- `counts`: a closure.
- `filters`: `{queue, search, class, tutor, from, to (Y-m-d|null), lesson}`.
- `queues`: `Option[]`.
- `classes` and `tutors`: `Option[]`.
- `selected`: a closure. It uses `?lesson` if that lesson exists, otherwise the first lesson of the current queue, otherwise null.

**`review`:**
- Validate `next` as nullable integer.
- Use `forceFill` to set the review fields, then save.
- Flash the toast "{class} marked as reviewed."
- Redirect to `route('admin.lesson-reports.index', $query)`. `$query` is `Arr::only` of the previous URL's query (`queue, search, class, tutor, from, to`), plus `lesson => next` when it is given.

**`raise`:**
- Validate `note` (required, string, max 1000).
- In a transaction, create the `RaisedIssue` (tutor = `lesson.tutor_id`, raised_by = the admin) and mark the lesson reviewed.
- Flash the toast "Sent to {tutor name}."
- Redirect the same way, with `lesson => this lesson`.

**Badge:**
- `HandleInertiaRequests::share` adds `'badges' => fn () => $user?->role === Role::Admin ? ['classReports' => $inbox->query($inbox->filters([]), 'review')->count()] : []`.

- [ ] Step 1: Write failing tests.
  - **Access:** the page is admin-only (tutor, student, guardian → 403), and so are `review` and `raise`.
  - **Index:** `lessons.data` rows are present, `counts.review` is right, `selected.id` is the first row's id when there's no `lesson` param, and `?lesson=X` selects X.
  - **Review:**
    - It sets the fields.
    - It redirects to the index with the referrer's `queue=review&class=…&lesson=<next>`.
    - Junk referrer keys are dropped: a `from(route(...).'&evil=1')` referrer doesn't carry `evil` through.
  - **Raise:**
    - It requires a note, and a 1001-character note fails.
    - It creates the issue for the lesson's tutor, marks the lesson reviewed, and redirects back to the lesson.
  - **Badge:** an admin page carries `badges.classReports`, which equals the Needs review count. A tutor page carries `badges` as `[]`.
- [ ] Step 2: Run them, and expect a FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run them, and expect a PASS. Commit: "feat: class reports inbox endpoints with review and raise".

---

### Task 4: Tutors read and acknowledge raised issues

**Files:**
- Create: `app/Http/Controllers/RaisedIssueController.php`
- Modify:
  - `routes/web.php`: inside the `auth, verified` group, `Route::put('raised-issues/{raisedIssue}/acknowledge', [RaisedIssueController::class, 'acknowledge'])->middleware('role:tutor')->name('raised-issues.acknowledge')`
  - `app/Http/Controllers/DashboardController.php`: tutor props `fromAcademy`
- Test: `tests/Feature/RaisedIssueTest.php`

**`fromAcademy`:**
- The tutor's unacknowledged issues, newest first.
- Each is `{id, className, when ('D j M'), note, raisedBy}`.
- Eager-load `lesson.classGroup` and `raisedBy`.

**`acknowledge`:**
- `abort_unless($raisedIssue->tutor_id === $request->user()->id, 403)`.
- Set `acknowledged_at ??= now()`.
- Flash the toast "Thanks. The academy can see you've read this."
- Return `back()`.

- [ ] Step 1: Write failing tests.
  - The tutor dashboard lists only the tutor's own open issues; another tutor's issue and an acknowledged one are absent.
  - The tutor can acknowledge their own issue, and a second time keeps the first timestamp.
  - Another tutor gets a 403.
  - An admin or a student gets a 403 from the role middleware.
- [ ] Step 2: Run them, and expect a FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run them, and expect a PASS. Commit: "feat: tutors acknowledge issues raised from class reports".

---

### Task 5: The inbox page, the badge and the tutor card (UI)

**Files:**
- Rewrite: `resources/js/pages/admin/lesson-reports/index.tsx`
- Create:
  - `resources/js/components/class-report-detail.tsx`
  - `resources/js/components/from-academy.tsx`
- Modify:
  - `resources/js/components/app-sidebar.tsx` (badge)
  - `resources/js/pages/dashboard/tutor.tsx` (the card above `ReportsDue`)

**Page layout:**
- **Header:** "End-of-class reports", with the subtitle "Feedback and recommendations from both sides of every class." and a search input on the right. The search is a GET `<Form>` that keeps the other filters as hidden inputs.
- **Tab strip:** `queues` with `counts`, as `Link`s with `preserveScroll`. Changing queue drops `lesson`. The active tab uses the primary style, as on the question bank page.
- **Filter row:** Class, Tutor, From and To, with an Apply button and a Reset link.
- **Panes (≥ lg):** two columns `[minmax(0,22rem)_minmax(0,1fr)]`.
  - **List card** ("Recent classes" in small caps):
    - It sits in `lg:sticky lg:top-6 lg:max-h-[calc(100dvh-3rem)] lg:overflow-y-auto`.
    - Rows are grouped under day headers (start a new header whenever `row.day` changes).
    - Each row shows the class name and the time on the right, the tutor below, and a status line with an icon and colour per state:
      - both: `check`, link colour;
      - pending: `clock`, muted;
      - missing: `alarm`, late colour;
      - raised: `flag`, danger;
      - reviewed: `check`, muted;
      - others: muted.
    - The selected row has a left primary border and a tinted background, as in the design.
    - Each row is a `Link` to the same URL with `lesson=id`, `preserveScroll`, `preserveState`, `only: ['selected', 'filters']`.
    - `<InfiniteScroll data="lessons" manual>` uses the next slot to render a "Load more" outline button. Check the slot API in the Inertia docs before writing it.
    - Empty state per queue, e.g. "Nothing waiting for review. Nice work." or "No classes match these filters."
  - **Detail** (`ClassReportDetail`):
    - **Header card:** the class name, then "{when} · {tutor} · {attendance}", with pills "Tutor report" and "{n} student reports". It shows "Reviewed by {by}, {at}" when reviewed.
    - **Tutor report card:** initials avatar, "Tutor report · {name}", "Submitted {time}" and "Lesson rating n / 5" on the right. "What went well" and "To improve" sit in two columns (one below sm), and a tinted Recommendation row has the `sparkle` icon.
    - **No tutor report:** a muted card reading "No tutor report yet." It adds "The window closes {time}" when pending.
    - **Student reports card:**
      - It's titled "Student reports" with "Shown to admin only" on the right.
      - A summary line: "{count} reports · average {x} / 5 · {n} need attention".
      - The first 3 reports are shown, with a "Show all {count}" toggle (local state, reset when `selected.id` changes, via `key`).
      - Each report has an avatar, name, "Rated n / 5", the quoted text (wentWell, then issues), and "Recommends: …" in the gold accent.
      - Needs-attention reports have a small `flag` badge "Needs attention".
    - **Raised issues:** listed under the detail as "Raised with {tutor} · {raisedAt}", with the note and "Waiting for the tutor" or "Read by the tutor {acknowledgedAt}".
    - **Action bar** (right-aligned):
      - "Raise with tutor" (outline) toggles an inline form: a Textarea `note` (max 1000, `FieldError`), a Send button, and Cancel.
      - "Mark as reviewed" (primary) is a `<Form {...review.form(id)}>` with a hidden `next`, which is the id of the row after the selected one in the loaded list, or the previous one when this is the last. It's hidden when the lesson is already reviewed and has no newer reports.
- **Below lg:**
  - Without `filters.lesson`, show the list only.
  - With it, show the detail only, with a "← All classes" link that clears `lesson`.
- **Sidebar:** `badges.classReports > 0` renders a small pill on "Class reports" (gold, like the design). Keep `NavItem` generic with an optional `badge` key.
- **Tutor dashboard:** `FromAcademy` is a card titled "From the academy" in the brand tint. Each issue shows "{className} · {when}", the note, "from {raisedBy}", and a "Got it" button (`<Form {...acknowledge.form(id)}>`).

- [ ] Step 1: Implement. Then run `npm run check:fix && npm run types:check && npm run build`, expecting it clean.
- [ ] Step 2: Run the PHP tests for the page props (the Task 3 tests still pass with the real component).
- [ ] Step 3: Take screenshots of the inbox at 360, 768 and 1280 with the local demo data (1280 without and with `?lesson=`, 360 for the list and the detail), and the tutor dashboard with one raised issue. There must be no horizontal scroll, and the look must match the design.
- [ ] Step 4: Commit: "feat: class reports inbox UI with tutor follow-up card".

## After the last task

- Run the full suite, then the final whole-branch review with a fresh reviewer.
