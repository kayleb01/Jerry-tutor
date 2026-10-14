# Lateness Reports Inbox

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the admin Lateness reports page on the same principle as the class reports inbox. It keeps the user's design:
- **List:** chips for New, Students and Tutors; each row shows the person, class and date, "Due · in", a minutes-late badge, and New or Followed up.
- **Detail:** scheduled start, actual arrival, date and minutes late, the person's recent history, a repeat-lateness warning, a follow-up note, Mark as followed up, and Notify parent.

It has to stay usable with hundreds of reports. "Notify parent" sends the student's guardians an in-app note, which they acknowledge on their dashboard. This mirrors "Raise with tutor".

**Architecture:**
- **List:** still one row per lateness report, using the existing `LatenessReportQuery` filters plus a `status` queue: `new` (not followed up, the default), `followed` or `all`. Rows come from `Inertia::scroll()` with manual "Load more" (`preserveUrl`). The selected report is in `?report=` and is a closure prop.
- **`App\Services\LatenessInbox`:** counts, the list row and the detail payload.
- **Detail payload:** includes the person's lateness over the last 90 days (newest 5, plus a total) and the repeat count over the last 30 days (≥3 shows the warning), using the existing repeat rule.
- **Mark as followed up:** reuses `PUT admin/lateness/{report}`. When `next` is sent, it redirects to the next report with the same filters, as the class reports inbox does.
- **Guardian notices:** a `guardian_notices` table holds `student_id, lateness_report_id, sent_by_id, note, acknowledged_at, acknowledged_by_id`. Every linked guardian sees the notice until one of them acknowledges it.
- **Sidebar:** "Lateness reports" gets a badge with the New count, alongside the class reports badge.

**Spec:**
- The user's "Lateness reports" screen.
- The instruction "apply the same principle as the [class reports]".
- The class reports inbox plan (`2026-10-14-class-reports-inbox.md`), for list, detail, next-row, mobile and badge behaviour.
- The Phase 4 rules: repeat lateness is 3 or more in 30 days; CSV export neutralises formulas.

## Global Constraints

- **Queues:** `new` is `reviewed_at` null; `followed` is `reviewed_at` set; `all` is everything. The chips show "New · n" counts. Role chips (Everyone, Students, Tutors) combine with the queue. Search, class and from/to filters stay as they are.
- **Export:** honours every filter, including the queue and role, and keeps formula neutralising.
- **Row:**
  - Fields: `id, name, role, className, date ('D j M', plus the year if not the current one), scheduled, arrived ('H:i' academy), minutesLate, status ('new'|'followed'), statusLabel`.
  - The detail adds `yearGroup` for students.
- **Detail fields:** `id, name, role, roleLabel, yearGroup, className, date, scheduled, arrived, minutesLate, reason, followUpNotes, followedUp{by, at} | null, history{items[{date, className, minutesLate}] (newest 5, excluding this report), total (last 90 days, including this one)}, repeatCount (last 30 days, including this one), canNotifyParent, guardians (names), notices[{id, note, sentBy, sentAt, acknowledgedAt, acknowledgedBy}]`.
  - `canNotifyParent` is true only for a student with at least one linked guardian.
- **Mark as followed up:** `PUT admin/lateness/{report}` with `reviewed=1`, `follow_up_notes` and an optional `next`. With `next`, it redirects to the inbox with the referrer's filters (`status, role, class, from, to, search`) plus `report=next`, and nothing else from the referrer. Without it, it redirects back, as before. Reopening (`reviewed=0`) is unchanged.
- **Notify parent:** `POST admin/lateness/{report}/notify-parent` with `note` (required, max 1000).
  - It returns 422 when the person is a tutor or has no guardian.
  - It creates one notice and also marks the report followed up if it isn't already, keeping any notes. Notifying is the follow-up action.
  - Toast: "Sent to {guardian names}." It stays on the report.
  - The note is prefilled in the UI with: "{first name} arrived {n} minutes late to {class} on {date} (the class started at {time})."
- **Guardians:**
  - The dashboard prop `fromAcademy` lists unacknowledged notices for the guardian's children, newest first, as `{id, childName, when, note, sentBy}`.
  - `PUT guardian-notices/{guardianNotice}/acknowledge` (`role:guardian`) is allowed only for a guardian linked to the notice's student; anyone else gets a 403. It is idempotent and records `acknowledged_by_id`.
- **Badges:** `badges.lateness` is the count of reports with `reviewed_at` null (admin only). The sidebar label becomes "Lateness reports", as in the design.
- **Local DB:** `php artisan migrate` only. Stage explicit paths. Run Pint, `check:fix` and `types:check` before each commit, and end commits with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Guardian notices are visible only to guardians linked to that student. Another guardian gets a 403 on acknowledge and doesn't see the notice. → Task 4.
2. Notify parent is refused for tutors and for students with no guardian (422), and creates no notice. → Task 3.
3. Queue, role and filter combine correctly, the counts respect the non-queue filters, and the export matches the list. → Tasks 2–3.
4. History and repeat counts are per person, not per class, and use academy dates. The 31-day-old edge stays out of the repeat count. → Task 2.
5. The next-report redirect keeps only the filter keys. → Task 3.

---

### Task 1: Guardian notices schema
- **Create:** a migration for `guardian_notices`, `app/Models/GuardianNotice.php` and its factory.
- **Modify:** `LatenessReport::guardianNotices(): HasMany`.
- **Table `guardian_notices`:**
  - `id`;
  - `student_id` (FK users);
  - `lateness_report_id` (nullable FK, nullOnDelete);
  - `sent_by_id` (nullable FK users, nullOnDelete);
  - `note` (text);
  - `acknowledged_at` (nullable datetime);
  - `acknowledged_by_id` (nullable FK users, nullOnDelete);
  - timestamps, with an index on (`student_id`, `acknowledged_at`).
- **Model:** relations `student`, `latenessReport`, `sentBy` and `acknowledgedBy` (users withTrashed); casts `acknowledged_at` and `created_at` to immutable.
- **Factory:** the default is a student user, an admin sender and a sentence, with state `acknowledged()`.
- **Test (in `tests/Feature/LatenessInboxTest.php`):** a factory notice links to its report and student, and the casts work.

### Task 2: LatenessInbox service and the status filter
- **Modify:** `LatenessReportQuery::filters()` adds `status` (`new` by default, then `followed`, then `all`). `filtered()` applies `whereNull` or `whereNotNull('reviewed_at')`.
- **Ruling:** the existing callers (export) pass the same filters.
- **Create `App\Services\LatenessInbox`:**
  - `counts(array $filters): array{new, followed, all}` applies every filter except status;
  - `row(LatenessReport): array`;
  - `detail(LatenessReport): array`.
- **Tests:**
  - **Queues and filters:** the queues split new and followed; the role and class filters combine with the queue; the counts ignore the queue but honour role and search; the default status is `new`; an unknown status falls back to `new`.
  - **History:** the person's other reports, newest first, at most 5. `total` counts the last 90 days including this report. Another person's reports are excluded.
  - **Repeat count:** three in the last 30 days gives 3, and one 31 days ago is not counted.
  - **Parent details:** `canNotifyParent` is false for a tutor, false for a student with no guardian, and true with a guardian, whose name is listed.
  - **Notices:** the list includes their acknowledgement state.

### Task 3: Admin endpoints, export and badge
- **`index`:**
  - `reports` (scroll), `counts` (closure), `filters` (+`status`, `report`), `classes`;
  - `selected` (closure): `?report` if it exists, otherwise the first row of the current list, otherwise null.
  - The old `repeat` prop is dropped, because the detail's repeat warning replaces it (ruling). Rewrite that test to assert `selected.repeatCount`.
- **`update`:** accepts `next`. When it's present, it redirects to `inboxUrl(next)`.
- **`notifyParent`:** as specified in Global Constraints.
- **Route:** `POST lateness/{latenessReport}/notify-parent`, named `admin.lateness.notify-parent`.
- **`HandleInertiaRequests`:** `badges.lateness` for admins.
- **Tests:**
  - The existing tests are kept and adapted: rows now carry `date` without the year when it's the current year.
  - The default queue lists only new reports, and `status=all` lists all of them.
  - `next` redirect: keeps the filters, drops other referrer keys.
  - Notify parent: 422 for a tutor or a student with no guardian; it creates the notice, marks the report followed up and keeps its notes; note validation; admin only.
  - Export with `status=followed` exports only followed-up reports.
  - The badge count.

### Task 4: Guardians read and acknowledge notices
- **Create** `GuardianNoticeController@acknowledge`.
- **Route** in the `auth, verified` group: `PUT guardian-notices/{guardianNotice}/acknowledge`, with `role:guardian`, named `guardian-notices.acknowledge`.
- **`DashboardController`:** guardian props `fromAcademy`.
- **Tests:**
  - A guardian sees only notices for their own children, and acknowledged ones are hidden.
  - A linked guardian acknowledges, and a second time keeps the first timestamp and the first acknowledger.
  - A different guardian gets a 403, and so do an admin and a tutor.

### Task 5: UI
- **Rewrite** `resources/js/pages/admin/lateness/index.tsx` in the class inbox pattern.
  - **Header:** "Lateness reports", eyebrow "Attendance", with the description "Created automatically whenever a student or a tutor is late." Actions: search plus an Export button (the export URL with the current filters).
  - **Chips:** New · n, Followed up, All; then Everyone, Students, Tutors.
  - **Filter row:** Class, From, To, Apply, Reset.
  - **List** (left on lg, sticky and scrollable): each row has an avatar, "Name · Role", "Class · Date", "Due {scheduled} · in {arrived}", a gold minutes badge, and a "New" (gold, bold) or "Followed up" (muted) label. The selected row has a left border and tint. There's a "Load more reports" button.
  - **Detail** (`lateness-report-detail.tsx`):
    - Avatar, name, and "Role · Year · Class".
    - A 2×2 tile grid: Scheduled start, Actual arrival (gold tint), Date, Minutes late.
    - The reason, if one was recorded.
    - "Recent lateness (last 90 days)", listing items with their minutes and "and n more".
    - The repeat warning (gold) when `repeatCount` is 3 or more: "{name} has been late {n} times in the last 30 days."
    - The follow-up note textarea, then the "Mark as followed up" primary full-width button, with hidden `reviewed=1` and `next`.
    - When already followed up: "Followed up by X, date", a "Save note" button, and a "Reopen" link button (`reviewed=0`).
    - "Notify parent" (outline, full width) when `canNotifyParent`. It opens an inline form prefilled with the note, saying "Goes to {guardians}", with Send and Cancel.
    - Any notices sent, with "Read by {name} {date}" or "Not read yet".
  - **Mobile:** list and detail switch as in the class inbox (`filters.report`), with an "All reports" back link, and scroll is preserved only at lg and up.
- **Sidebar:** add the `badge: 'lateness'` key; the label becomes "Lateness reports".
- **Guardian dashboard:** a "From the academy" card, reusing `FromAcademy` generalised to take the acknowledge form per note and a heading line per note.
- **Remove** `resources/js/components/lateness-follow-up.tsx` once unused.
- **Screenshots:** 1280 (list and detail) and 360 (list, and detail with `?report=`), with no horizontal scroll.

## After the last task
Run the full suite, then the final review with a fresh reviewer.
