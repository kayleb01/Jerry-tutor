# Mobile Quick Actions and Student Check-in Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A bottom quick-action bar on phones for every role, and students checking in to class with a 4-digit code the tutor shows, with a live clock and a time stamp.

**Architecture:** Each lesson gets a lazily generated `check_in_code`. A new `LessonPolicy::studentCheckIn` rule and a `StudentCheckIn` service find the student's open lesson. A `StudentCheckInController` writes attendance through the existing `RecordAttendance` action (same as `TutorCheckInController`, minus the lesson status change). The register page polls while check-in is open, and its save skips self-check-ins newer than the page. The bar is a `QuickActionBar` component rendered by `AppLayout` below `lg`.

**Tech Stack:** Laravel 12 / PHP 8.4, Inertia v3 + React, Wayfinder, Tailwind v4, Pest.

**Spec:** `docs/superpowers/specs/2026-11-14-mobile-quick-actions-and-student-check-in-design.md`

## Global Constraints

- All attendance writes go through `App\Actions\RecordAttendance::handle()`. Student check-in passes `AttendanceStatus::Present`, `CarbonImmutable::now()` (server time) and `onlyIfUnrecorded: true`.
- Student check-in never changes `lessons.status`.
- Check-in window: `starts_at - 30 min` ≤ now ≤ `ends_at`. The lesson isn't cancelled, and the student is an active member (`activeStudents()`) of `lesson.classGroup`.
- Code: 4 numeric digits, zero-padded, stored once per lesson in `lessons.check_in_code` (nullable `string(4)`), additive migration only. Never use `migrate:fresh`.
- Wrong-code limit: 5 tries per student per lesson, 10-minute decay (key `student-check-in:{studentId}:{lessonId}`).
- Copy (exact):
  - wrong code: "That code isn't right. Check the number on your tutor's screen."
  - limit: "Too many tries. Ask your tutor."
  - none open: "No class to check in to right now"
  - register flash: "{name} checked in at {HH:MM} while you were editing. Their check-in was kept."
  - register code line: "Students check in with this code"
- All times in `config('academy.timezone')`.
- Links via Wayfinder (`@/routes/...`). After adding routes, run `php artisan wayfinder:generate`.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes. Stage explicit paths only (the user edits files at the same time).

## Review Focus

1. **A student checks in, then the tutor saves a stale register.** The check-in must survive. Pinned in Task 4.
2. **A student is removed from the class (`left_at` set) but is still logged in.** Check-in must be refused. Pinned in Task 1.
3. **Exactly at `ends_at`, the window is still open. One second later, it's closed.** Pinned in Task 1.
4. **A tutor or admin already marked the student absent before check-in.** A correct code must not overwrite it, and the page shows "Marked absent by your tutor". Pinned in Task 2.
5. **Another class's tutor or another student sees a lesson's code.** They mustn't. The code prop is only for the lesson's tutor or an admin. Pinned in Task 3.

---

### Task 1: Lesson check-in code and the student check-in rule

**Files:**
- Create: migration `add_check_in_code_to_lessons_table` (via `php artisan make:migration`)
- Modify: `app/Models/Lesson.php` (fillable, `checkInCode()`, `isOpenForStudentCheckIn()`)
- Modify: `app/Policies/LessonPolicy.php` (`studentCheckIn`)
- Test: `tests/Feature/StudentCheckInPolicyTest.php`

**Interfaces:**
- Produces:
  - `Lesson::checkInCode(): string`. Returns the stored code, generating and saving one (`str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)`) if it's null.
  - `Lesson::isOpenForStudentCheckIn(?CarbonImmutable $now = null): bool`. The window rule only (not cancelled, in the time window).
  - `LessonPolicy::studentCheckIn(User $user, Lesson $lesson): bool`. The window rule, plus the user is a student, plus the user is in `$lesson->classGroup->activeStudents()`.

- [ ] **Step 1: Write failing tests**
  - `it('generates a four digit code once and keeps it')`. Calling `checkInCode()` twice returns the same `/^\d{4}$/` string, and `fresh()->check_in_code` equals it.
  - `it('lets an enrolled student check in from 30 minutes before start until the end')`. The lesson runs 09:00–10:00 UTC. `travelTo` 08:29:59 → false, 08:30 → true, 10:00:00 → true, 10:00:01 → false.
  - `it('refuses a student who left the class')`. Enrol, then `$group->unenrol($student)` (or set `left_at`) → false at 09:05.
  - `it('refuses a cancelled lesson, a tutor and a student of another class')` → all false at 09:05.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/StudentCheckInPolicyTest.php`. Expected: FAIL (method not defined).
- [ ] **Step 3: Implement** the migration (`$table->string('check_in_code', 4)->nullable()->after('status')`, with `down()` dropping it), the two `Lesson` methods and the policy method, as in Interfaces. Run `php artisan migrate --no-interaction`.
- [ ] **Step 4: Run** the same test. Expected: PASS.
- [ ] **Step 5: Commit** `feat(check-in): lesson check-in code and student check-in rule`.

### Task 2: Student check-in page and submit

**Files:**
- Create: `app/Services/StudentCheckIn.php`
- Create: `app/Http/Controllers/StudentCheckInController.php` (`show`, `store`)
- Create: `app/Http/Requests/StudentCheckInRequest.php`
- Modify: `routes/web.php` (in the `auth, verified, approved.tutor` group: `Route::middleware('role:student')`, `GET /check-in` → `check-in.show`, `POST /check-in/{lesson}` → `check-in.store`)
- Create: `resources/js/pages/check-in/index.tsx`, `resources/js/components/live-clock.tsx`
- Test: `tests/Feature/StudentCheckInTest.php`

**Interfaces:**
- Consumes: Task 1's `studentCheckIn` policy, `Lesson::checkInCode()`.
- Produces:
  - `StudentCheckIn::openLessonFor(User $student, ?CarbonImmutable $now = null): ?Lesson`. This is the earliest-starting lesson the student passes `studentCheckIn` for (in progress first, else starting within 30 min).
  - `StudentCheckIn::stamp(Lesson $lesson, User $student): ?array{time: string|null, status: string, minutesLate: int|null, recordedBySelf: bool}`. Null when there's no record.
  - `StudentCheckIn::card(User $student): ?array{lessonId: int, title: string, startsAt: string /* HH:MM */, stamp: array|null}`. Null when nothing is open.
  - `StudentCheckIn::nextLessonLabel(User $student): ?string`, e.g. `"Tue 09:00 · Year 5 · Maths"`.
  - Page props for `check-in/index`: `{ card: Card|null, next: string|null, timezone: string }`.
  - `LiveClock` React component: `({ timezone: string; frozenAt?: string | null }) => JSX`. Ticks HH:MM:SS each second with `Intl.DateTimeFormat('en-GB', { timeZone, hour12: false, ... })`. It renders `frozenAt` and stops ticking when that's given.

- [ ] **Step 1: Write failing tests** (lesson 09:00–10:00 UTC, academy timezone `Africa/Lagos`, grace 0)
  - `it('shows the open lesson with a live clock')`. At 08:50, `get(route('check-in.show'))` returns component `check-in/index`, with `card.title` = the lesson title, `card.startsAt` = `'10:00'`, `card.stamp` null, and `timezone` = `'Africa/Lagos'`.
  - `it('says when the next class is when nothing is open')`. At 07:00, `card` is null and `next` is not null.
  - `it('checks the student in with the right code and keeps the lesson scheduled')`. At 09:04, post `code` = `$lesson->checkInCode()`. The record is Late with `minutes_late` 4, `recorded_by_id` is the student, and `lesson.status` is still `Scheduled`.
  - `it('records nothing for a wrong code')`. The response has a session error on `code` with the exact wrong-code copy, and there are 0 records.
  - `it('locks out after five wrong codes')`. On the 6th try, even with the right code, the error is the limit copy and there are 0 records.
  - `it('does not overwrite what the tutor already recorded')`. Pre-record Absent via `RecordAttendance`, post the right code → still Absent. `show` gives `card.stamp.status` = `'absent'` and `recordedBySelf` false.
  - `it('refuses a lesson the student cannot check in to')`. Another class's lesson → 403.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/StudentCheckInTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the service, the request and the controller.
  - `StudentCheckInRequest`:
    - `authorize` uses `can('studentCheckIn', lesson)`;
    - rules: `code` must be `required|digits:4`;
    - in `after()`: if `RateLimiter::tooManyAttempts(key, 5)`, add the limit copy. Else, if the code doesn't match `lesson->check_in_code`, call `RateLimiter::hit(key, 600)` and add the wrong-code copy.
  - `store` calls `RecordAttendance` as in Global Constraints, flashes the toast "Checked in at HH:MM · On time" or "· N min late", and redirects to `check-in.show`. It never touches `lessons.status`.
  - Page:
    - when a card is open and there's no stamp: the title, "Class starts HH:MM", `LiveClock`, a code `Input` (`inputMode="numeric"`, `maxLength={4}`, `autoComplete="one-time-code"`) and a primary **Check in** button, using Wayfinder `store.form(lessonId)`;
    - when there's a stamp: a frozen `LiveClock` plus "Checked in at 09:03 · On time / · 4 min late". If there's no time, "Marked absent by your tutor" / "Marked {status} by your tutor";
    - when there's no card: the none-open copy plus `next`.
  - Run `php artisan wayfinder:generate`.
- [ ] **Step 4: Run** the test. Expected: PASS. Then run `npm run build`. Expected: no TS errors.
- [ ] **Step 5: Commit** `feat(check-in): students check in with the class code`.

### Task 3: Tutors see the code, and the register marks self check-ins

**Files:**
- Modify: `app/Http/Controllers/RegisterController.php` (`show`: `checkInCode` prop; `row()`: `selfCheckIn`)
- Modify: `app/Services/TutorDashboard.php` (`nextLesson()`: `checkInCode`)
- Modify: `resources/js/pages/lessons/register.tsx`, `resources/js/components/register-row.tsx` (`RegisterPerson.selfCheckIn: boolean`, marker "Self check-in" next to the name)
- Modify: `resources/js/pages/dashboard/tutor.tsx` (show the code in the next-class banner)
- Test: add to `tests/Feature/RegisterTest.php` and `tests/Feature/TutorDashboardTest.php`

**Interfaces:**
- Consumes: `Lesson::checkInCode()`, `Lesson::isOpenForStudentCheckIn()`.
- Produces: register prop `checkInCode: string|null`. This is the code when the viewer is the lesson's tutor or an admin and `isOpenForStudentCheckIn()`, else null. Tutor dashboard `nextLesson.checkInCode: string|null`, using the same rule, plus `isToday`. Register row `selfCheckIn: bool` (`record.recorded_by_id === person.id && person.id !== lesson.tutor_id`).

- [ ] **Step 1: Write failing tests**
  - `it('shows the lesson tutor and admins the check-in code while check-in is open')`. At 09:05, the tutor and an admin get `checkInCode` matching `/^\d{4}$/`. At 08:00, they get null.
  - `it('marks a student who checked in themselves')`. Record via `RecordAttendance` with `recordedBy` = the student → that row has `selfCheckIn` true.
  - Tutor dashboard: at 08:45, `nextLesson.checkInCode` equals `$lesson->fresh()->check_in_code`.
- [ ] **Step 2: Run** `php artisan test --compact --filter='check-in code|checked in themselves'`. Expected: FAIL.
- [ ] **Step 3: Implement.** On the register page, show the code in large tabular digits in a `Card` above the counts, with the line "Students check in with this code". On the dashboard banner, show the code in a small labelled box.
- [ ] **Step 4: Run** the tests. Expected: PASS. Then run `npm run build`.
- [ ] **Step 5: Commit** `feat(check-in): show the class code to tutors and mark self check-ins`.

### Task 4: Live register and safe save

**Files:**
- Modify: `app/Http/Controllers/RegisterController.php` (`show`: `loadedAt` and `pollForCheckIns` props; `update`: skip newer self-check-ins)
- Modify: `app/Http/Requests/UpdateRegisterRequest.php` (`loaded_at` → `nullable|date`)
- Modify: `resources/js/pages/lessons/register.tsx`
- Test: `tests/Feature/RegisterTest.php`

**Interfaces:**
- Produces:
  - `loadedAt: string`. ISO-8601 server time when the props were built.
  - `pollForCheckIns: bool`. `canEdit && isOpenForStudentCheckIn()`.
  - The form posts a hidden `loaded_at`.

- [ ] **Step 1: Write failing tests**
  - `it('keeps a self check-in made after the tutor opened the register')`. At 09:00, the tutor loads the register (capture `loadedAt`). At 09:03, the student records via `RecordAttendance` with `recordedBy` = the student. At 09:10, the tutor `put`s `attendance[student] = absent` with `loaded_at`. Afterwards the record is still Present/Late with `arrived_at` 09:03, no absence notice exists, and the flash toast contains "checked in at 10:03 while you were editing".
  - `it('lets the tutor change a self check-in they have seen')`. Same setup, but `loaded_at` is after 09:03 → the record is Absent.
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/RegisterTest.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement**
  - In `update`, before `isUnchanged`: skip a row (and collect name plus local arrival time) when `filled(loaded_at)`, `$record?->recorded_by_id === (int) $userId`, the person isn't the tutor, and `$record->updated_at > Carbon::parse(loaded_at)`.
  - The flash is "Register saved." followed by one sentence per kept student, using the exact copy.
  - Client:
    - `usePoll(15000, { only: ['students', 'tutor', 'loadedAt'] }, { autoStart: pollForCheckIns })`;
    - track a `touched: Set<number>` updated in `setMark`;
    - in a `useEffect` on `students`, reset `marks[id]` with `initialMark` for each untouched row;
    - add a hidden input `loaded_at` = `loadedAt` from the props at first render (keep it in a `useRef` so polling doesn't move it forward for rows the tutor already edited).
- [ ] **Step 4: Run** the tests. Expected: PASS. Then run `npm run build`.
- [ ] **Step 5: Commit** `feat(register): refresh during check-in and keep newer self check-ins`.

### Task 5: Student dashboard check-in card

**Files:**
- Modify: `app/Services/StudentDashboard.php` (`'checkIn' => app(StudentCheckIn::class)->card($student)`)
- Modify: `resources/js/pages/dashboard/student.tsx` (a card at the top when `checkIn` is set: "{title} starts at {startsAt}" plus a **Check in** button to `check-in.show`, or the stamp line once recorded)
- Test: `tests/Feature/StudentDashboardTest.php`

- [ ] **Step 1: Write failing test.** `it('offers check-in on the dashboard while a class is open')`: at 08:50 `checkIn.title` is set and `checkIn.stamp` is null; at 07:00 `checkIn` is null.
- [ ] **Step 2: Run** `php artisan test --compact --filter='offers check-in'`. Expected: FAIL.
- [ ] **Step 3: Implement** as in Files.
- [ ] **Step 4: Run** the test. Expected: PASS. Then run `npm run build`.
- [ ] **Step 5: Commit** `feat(check-in): check-in card on the student dashboard`.

### Task 6: Quick-action bar and matching tutor labels

**Files:**
- Create: `resources/js/components/quick-action-bar.tsx`
- Modify: `resources/js/layouts/app-layout.tsx` (render the bar below `lg`, add `pb-[calc(5rem+env(safe-area-inset-bottom))] lg:pb-12` to `<main>`, pass `onMore={() => setDrawerOpen(true)}`)
- Modify: `resources/js/components/app-sidebar.tsx`:
  - tutor labels: "My classes" → "Students", "Take the register" → "Attendance", "End-of-class report" → "Report";
  - add a student "Check in" item;
  - export `navItems`, the `NavItem` type and an `isActivePath` helper for reuse.
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share `quizAccess: fn () => $user?->role === Role::Student ? $user->hasQuizAccess() : null`)
- Modify: `resources/js/types/global.d.ts` (`quizAccess?: boolean | null`)
- Test: `tests/Feature/DashboardTest.php` (shared prop)

**Interfaces:**
- `QuickActionBar({ onMore }: { onMore: () => void })`. Reads `auth`, `badges`, `pendingTutorCount` and `quizAccess` from `usePage().props`.
- Buttons by role, in order:

| Role | Buttons |
|---|---|
| tutor | Home (`dashboard`), Students (`tutor.classes.index`), Attendance (`registers.next`), Report (`reports.next`), More |
| student | Home, Check in (`check-in.show`), Tests (`quizzes.index`, only when `quizAccess`), More |
| guardian | Home, Children (`children.index`), Billing (`billing.index`), More |
| admin | Home, Attendance (`admin.attendance.index`), Users (`admin.users.index`, badge `pendingTutors`), Class reports (`admin.lesson-reports.index`, badge `classReports`), More |

- The bar is `fixed inset-x-0 bottom-0 z-30 border-t bg-surface lg:hidden` with `pb-[env(safe-area-inset-bottom)]` and a `grid` of equal columns. Each item is at least 56px tall, with the icon above a short label (`text-[0.6875rem]`). The active item uses `text-primary font-semibold` and `aria-current="page"`. More is a `<button aria-label="More">`.

- [ ] **Step 1: Write failing test.** `it('shares quiz access with students only')`: a student gets `quizAccess` as a bool, and a tutor gets null.
- [ ] **Step 2: Run** `php artisan test --compact --filter='shares quiz access'`. Expected: FAIL.
- [ ] **Step 3: Implement** as in Files and Interfaces. Check that the `index.html` viewport meta includes `viewport-fit=cover` for the safe area (in `resources/views/app.blade.php`; add it if it's missing).
- [ ] **Step 4: Run** the test plus `php artisan test --compact tests/Feature/DashboardTest.php`. Expected: PASS. Then run `npm run build` and `npm run lint` if it exists.
- [ ] **Step 5: Verify in the browser** at a 390px width as each role. Check that the bar shows, the right item is highlighted, More opens the drawer, and the page's last content isn't hidden behind the bar.
- [ ] **Step 6: Commit** `feat(nav): mobile quick-action bar`.
