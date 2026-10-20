# Class Timetables, Subjects and Tutor Sign-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Classes get multi-subject weekly timetables (slots with their own tutor), tutors apply through a public bot-protected page and wait for admin approval with per-class handover, and approved tutors add students to their classes.

**Architecture:**
- Slots are the template that lessons are generated from. Lessons keep snapshotting `tutor_id` and times, so everything downstream that reads `lessons.tutor_id` is untouched.
- A lesson's display name becomes `Lesson::title()` ("{class} · {subject}").
- Tutor approval is a nullable `users.approval_status`, enforced by one middleware on the signed-in route group.

**Tech Stack:** Laravel 12 / PHP 8.4, Fortify, Inertia v3 + React + TypeScript, Wayfinder, Tailwind, Pest.

**Spec:** `docs/superpowers/specs/2026-10-20-timetables-and-tutor-signup-design.md`

## Global Constraints

- Local DB holds user data: `php artisan migrate` only, never fresh or reseed. Migrations must carry existing classes and lessons over with no loss.
- Account invariants:
  - usernames match `^[a-z0-9._-]+$` (`App\Concerns\AccountValidationRules`, `normaliseAccountInput()`);
  - emails are lowercased;
  - `role` is never fillable and never changes;
  - users are soft-deleted;
  - an account created by a trusted person (admin, tutor, guardian) with an email gets `email_verified_at = now()` (`App\Actions\CreateStudentAccount` already does this).
- Lessons keep their own `tutor_id`, `starts_at` and `ends_at`. Downstream code reads `lessons.tutor_id`. A slot tutor change rebuilds only future, scheduled, unattended lessons (the existing `rescheduleFuture` rule).
- Times are shown in `config('academy.timezone')`.
- Copy (verbatim):
  - waiting page: "Thanks — your application is with the academy team. We'll email you when it's approved."
  - rejected page: "Your application wasn't approved. Please contact the academy."
  - login link: "Joining as a tutor? Apply here"
  - approval email: "You're approved — you can now log in and see your classes."
  - overlap error: "{class} already has {subject} {HH:MM}–{HH:MM} on {Day}"
- Bot protection on `/tutors/join`: a hidden honeypot must be empty; at least 3 seconds between render and submit (encrypted timestamp); `throttle` 5/minute per IP; an application is listed to admins only once its email is verified.
- Process: stage explicit paths only. Run Pint (`vendor/bin/pint --dirty --format agent`), `npx vp check --fix <files>`, `npm run types:check` and `php artisan wayfinder:generate --with-form` after route changes. Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Existing data after the migration:** a class with no schedule, a class with no tutor, and lessons that already have attendance and reports all survive. Covered by Task 2's migration test.
2. **A pending or rejected tutor deep-linking** to `/lessons/{id}/register`, `/report` or `/tutor/classes` is shown the waiting or rejected page, not data. Covered by Task 5.
3. **A handover where the slot's next lesson already has attendance** keeps that lesson with the old tutor. Covered by Task 7.
4. **A tutor posting to another tutor's class** student-creation URL gets 403. Covered by Task 8.
5. **A sign-up for an inactive class or subject**, or the same pair twice, is rejected with field errors. Covered by Task 6.

---

### Task 1: Subjects

**Files:**
- Create: the `Subject` model, migration (`subjects`: id, name unique, is_active bool default true, sort_order unsigned small int default 0, timestamps; the migration inserts "Maths"), factory, `App\Http\Controllers\Admin\SubjectController` (index, store, update), request `App\Http\Requests\Admin\SubjectRequest`, page `resources/js/pages/admin/subjects/index.tsx`
- Modify: `routes/web.php` (admin group: `admin.subjects.index|store|update`), `resources/js/components/app-sidebar.tsx` (admin item "Subjects", after Classes, icon `book`)
- Test: `tests/Feature/Admin/SubjectsTest.php`

**Interfaces:**
- Produces: `App\Models\Subject` with `#[Scope] active(Builder $query)` (is_active, ordered by sort_order then name) and `static options(): array<int, array{value:int,label:string}>` (active only).

- [ ] **Step 1: Write failing tests:**
  - `it('starts with Maths')`;
  - `it('lets an admin add, rename and deactivate a subject')`;
  - `it('refuses a duplicate subject name, ignoring case')`;
  - `it('is for admins only')`, with a dataset of tutor, student and guardian → 403.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** The page is one card listing subjects (name, Active/Inactive badge, an inline rename form, a Deactivate/Activate toggle) plus an "Add subject" form. Follow `admin/attendance/index.tsx` styling.
- [ ] **Step 4: Run** `tests/Feature/Admin/SubjectsTest.php`, plus lint, types and build. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(admin): subjects list`.

### Task 2: Class slots, data migration and per-slot lesson generation

**Files:**
- Create:
  - the `ClassSlot` model (SoftDeletes) and factory;
  - migration A: create `class_slots`, with id, class_group_id FK cascade, subject_id FK restrict, tutor_id nullable FK users nullOnDelete, weekday unsigned tinyint nullable, start_time time nullable, duration_minutes unsigned small int default 60, location string nullable, softDeletes and timestamps;
  - migration B (data plus schema): add `lessons.class_slot_id` nullable FK to class_slots. For each class group, insert one slot (subject Maths; the class's tutor_id, weekday, start_time, duration_minutes, location) and set `class_slot_id` on its lessons. Then make `class_slot_id` NOT NULL, add unique `(class_slot_id, starts_at)`, and drop `class_groups.tutor_id`, `weekday`, `start_time`, `duration_minutes` and `location`. Write a `down()` that restores them from each class's first slot.
- Modify:
  - `app/Models/ClassGroup.php`: remove tutor and schedule; add `slots(): HasMany`; add `#[Scope] taughtBy(Builder $query, User $tutor)` (has a non-deleted slot with that tutor_id). Remove `hasSchedule()` and `scheduleLabel()`, moving them to ClassSlot.
  - `app/Models/Lesson.php`: `class_slot_id` fillable; `slot(): BelongsTo` withTrashed.
  - `app/Models/User.php`: `classSlotsTaught(): HasMany` (tutor_id). Remove `classGroupsTaught()`, replacing callers with `ClassGroup::taughtBy($user)`.
  - `app/Services/LessonScheduler.php`: per slot (see Interfaces).
  - `app/Console/Commands/GenerateLessons.php`.
  - `app/Http/Controllers/Admin/UserController.php:138-141`: deactivation clears `tutor_id` on the tutor's slots and reschedules each.
  - `app/Services/StudentsToWatch.php:45` and `app/Services/AdminDashboard.php:206,215`: tutor → classes via slots.
  - Location readers `RegisterController.php:84`, `StudentDashboard.php:111`, `TutorDashboard.php:114`: read `$lesson->slot->location`.
  - `database/factories/{ClassGroup,Lesson,LessonReport,RaisedIssue}Factory.php`. `ClassGroupFactory` no longer has tutor or schedule. Add `ClassGroupFactory::withSlot(?User $tutor = null, array $slot = [])`, which creates a Maths slot (random weekday, 10:00, 60 minutes, a new tutor by default). `LessonFactory` requires or creates a slot and copies its tutor.
  - `database/seeders/DatabaseSeeder.php` and `DemoDataSeeder.php`: slots, including one class with two subjects (Maths and Science with different tutors on the same day) when an English or Science subject is created.
  - The existing tests that build classes with tutors: update them to `->withSlot(...)`. The ~13 files are listed in the spec research, e.g. `tests/Feature/LessonSchedulerTest.php`, `ClassGroupTest.php`, `RegisterTest.php`.
- Test: `tests/Feature/ClassSlotMigrationTest.php` and `tests/Feature/LessonSchedulerTest.php` (rewritten for slots).

**Interfaces:**
- Produces:
  - `App\Models\ClassSlot`:
    - relations `classGroup()`, `subject()`, `tutor()` (withTrashed), `lessons()`;
    - casts `weekday` → `App\Enums\Weekday|null`; `startTime` accessor/mutator ("HH:MM" read, "HH:MM:00" stored), as on ClassGroup today;
    - `hasSchedule(): bool`;
    - `timeLabel(): string` → "Mon 09:00–11:00", or "No regular time" when unscheduled.
  - `ClassGroup::slots(): HasMany<ClassSlot>`, `ClassGroup::scopeTaughtBy`.
  - `LessonScheduler`:
    - `generate(?int $days = null): int` (all scheduled slots with a tutor, of active classes);
    - `generateFor(ClassSlot $slot, ?int $days = null): int`;
    - `rescheduleFuture(ClassSlot $slot): void` (deletes the slot's future, scheduled, unattended lessons, then regenerates, all in one transaction);
    - `removeFuture(ClassSlot $slot): void` (deletes them without regenerating).
  - Lessons are created with `firstOrCreate(['class_slot_id','starts_at'], ['class_group_id','tutor_id','ends_at','status'])`.

- [ ] **Step 1: Write failing tests:**
  - `ClassSlotMigrationTest`: build the old shape by running the migrations up to before migration B (use `$this->artisan('migrate:rollback', ['--step' => 1])` inside the test DB, insert raw old-shape rows, then `migrate`). Assert:
    - a scheduled class with a tutor becomes one Maths slot with the same tutor, weekday, start, length and room;
    - its lessons carry the slot id;
    - an unscheduled class becomes a slot with null weekday and start time;
    - a tutorless class becomes a slot with a null tutor;
    - attendance and reports are untouched.
  - `LessonSchedulerTest`:
    - `it('generates lessons for each slot of a class with that slot\'s tutor and times')`: Year 5 with Maths Mon 09:00–11:00 (tutor A) and Science Mon 12:00–14:00 (tutor B) → two lessons each Monday, with the right tutors;
    - `it('skips slots without a tutor or a time, and inactive classes')`;
    - `it('reschedules only the edited slot and keeps attended lessons')`;
    - `it('removing a slot deletes its future unattended lessons and keeps the past')`.
  - `it('clears a deactivated tutor from their slots')` in `tests/Feature/Admin/UserLifecycleTest.php`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** Keep the existing transaction and lock pattern in `generateFor`.
- [ ] **Step 4: Run** `php artisan test --compact tests/Feature` (the whole feature suite, since factories change everywhere). Expected: PASS. Then run `php artisan migrate` locally and check `php artisan tinker --execute 'echo App\Models\ClassSlot::count();'` equals the class count.
- [ ] **Step 5: Commit**: `feat(classes): timetable slots with their own subject and tutor`.

### Task 3: Lesson titles "{class} · {subject}"

**Files:**
- Modify: `app/Models/Lesson.php` (`title(): string`). Then every place that names a **lesson** with `classGroup->name`: RegisterController, LessonReportController (classOptions, panel), TutorDashboard (week rows, nextLesson, oldestLabel), TutorReports consumers, StudentDashboard, AdminDashboard (today, latest reports, lateness), AttendanceLog (row and CSV `Class` column → `Lesson`), ClassReportInbox, ReportsDue, GuardianDashboard (`nextLesson.className`), AlertGuardiansOfAbsence + AbsenceMail, and RecordAttendance's `LatenessReport.class_name` snapshot. Find them with `grep -rn "classGroup->name\|classGroup\.name\|class_name" app resources/js`.
- Test: update the existing tests' expected strings; add `tests/Unit` or feature coverage for `title()`.

**Interfaces:**
- Consumes: `Lesson::slot()`, `ClassSlot::subject()` (Task 2).
- Produces: `Lesson::title(): string` = "{classGroup.name} · {slot.subject.name}", **except** when the class name already contains the subject name as a whole word (case-insensitive), then just the class name. Existing classes are named like "Year 3 & 4 Maths" and must not become "Year 3 & 4 Maths · Maths".

- [ ] **Step 1: Write failing tests:**
  - `it('names a lesson by class and subject')`: class "Year 5" + Science → "Year 5 · Science";
  - `it('does not repeat a subject already in the class name')`: "Year 5 Maths" + Maths → "Year 5 Maths"; "Year 5 Maths" + Science → "Year 5 Maths · Science";
  - update one representative screen assertion (a Science slot's register shows "Year 5 · Science") and see it fail.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement and sweep.** Eager-load `slot.subject` wherever lessons are listed, so there is no N+1. Class-level pages (admin classes, guardian "Year 5 Maths" class labels, attendance filter class options) keep the class name.
- [ ] **Step 4: Run** the full feature suite. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(lessons): name lessons by class and subject`.

### Task 4: Admin timetable editor

**Files:**
- Create: `App\Http\Controllers\Admin\ClassSlotController` (store, update, destroy), `App\Http\Requests\Admin\ClassSlotRequest`, `resources/js/components/class-slot-form.tsx`
- Modify: `ClassGroupController` and `ClassGroupRequest` (class fields only: name, year group, active), `resources/js/components/class-group-fields.tsx`, `resources/js/pages/admin/classes/{create,edit,index}.tsx`, `routes/web.php` (`admin.classes.slots.store|update|destroy`, nested `classes/{classGroup}/slots/{slot}` with scoped binding)
- Test: `tests/Feature/Admin/ClassTimetableTest.php`; update `tests/Feature/Admin/ClassGroupManagementTest.php`.

**Interfaces:**
- Consumes: `ClassSlot`, `LessonScheduler::generateFor/rescheduleFuture/removeFuture` (Task 2), `Subject::options()` (Task 1).
- Produces: `ClassSlotRequest`, which validates:
  - `subject_id` (active subject on create; the current one is allowed on update);
  - `tutor_id` (nullable; an active, non-deleted tutor. Task 5 adds "and approved");
  - `weekday` + `start_time` (nullable together);
  - `duration_minutes` (15–240);
  - `location` (nullable, max 255);
  - **no overlap** with the class's other non-deleted, scheduled slots on the same weekday ([start, start+duration) intersects), with the Global Constraints error copy.
  - Its overlap check is a reusable `public static function overlapping(ClassGroup $group, Weekday $day, string $start, int $minutes, ?int $ignoreSlotId = null): ?ClassSlot` on `ClassSlot`, which Task 7 reuses.

- [ ] **Step 1: Write failing tests:**
  - `it('adds a slot and generates its lessons')`;
  - `it('edits a slot and reschedules only that slot')`;
  - `it('refuses a slot that overlaps another slot of the class on the same day')`: exact copy "Year 5 already has Maths 09:00–11:00 on Monday"; adjacent 11:00 is OK;
  - `it('removes a slot, deleting future unattended lessons only')`;
  - `it('lists each class with its subjects and tutors')` (index props);
  - admin-only 403s.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
  - **Edit page:** the class details form, then a "Timetable" card with a row per slot ("Mon 09:00–11:00 · Maths · Mr Bello · Room 2") with Edit and Remove, and an "Add slot" form (subject, tutor, day, start, length, room).
  - **Index:** each class shows its slots as small lines.
  - **Create:** class details only, then redirect to edit, to add slots.
- [ ] **Step 4: Run** the task's tests, the class management tests, lint, types and build. Then do a browser check as `admin@academy.test`: the edit page at 1440 and 360, no saving. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(admin): edit a class's weekly timetable`.

### Task 5: Tutor approval state and lock-out

**Files:**
- Create: `App\Enums\ApprovalStatus` (Pending, Approved, Rejected), migration (`users.approval_status` nullable string; backfill `approved` for existing tutors), middleware `App\Http\Middleware\EnsureTutorIsApproved` (alias `approved.tutor` in `bootstrap/app.php`), `App\Http\Controllers\TutorApplicationStatusController` (shows the page), page `resources/js/pages/auth/tutor-application-status.tsx`
- Modify: `app/Models/User.php` (cast; `isApprovedTutor(): bool`; `isPendingTutor()`; `isRejectedTutor()`), `app/Http/Requests/Admin/ClassSlotRequest.php` (the tutor must also be approved), `routes/web.php` (add `approved.tutor` to the `['auth','verified']` groups; route `GET /tutors/application` named `tutors.application`, under `auth` + `verified` only), `UserController::store` (admin-created tutors get `approval_status = approved`), `UserFactory` (the `tutor()` state is approved; add `pendingTutor()` and `rejectedTutor()`)
- Test: `tests/Feature/TutorApprovalGateTest.php`

**Interfaces:**
- Produces: `ApprovalStatus` enum; `User::isApprovedTutor()`. The middleware redirects pending and rejected tutors to `tutors.application`, and passes everyone else.

- [ ] **Step 1: Write failing tests:**
  - `it('sends a pending tutor to the waiting page from any signed-in page')`: a dataset of `/dashboard`, `/take-register`, `/report`, a lesson's register URL → redirect to `tutors.application`, which shows the waiting copy;
  - `it('shows a rejected tutor the not-approved page')`;
  - `it('lets approved tutors and other roles through')`;
  - `it('approves tutors an admin creates')`;
  - `it('approves every tutor that existed before')` (migration backfill: a tutor with null status before the migration ends up approved; or assert via factory default plus the migration test).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** The status page is a centred card in the auth layout (see `auth/verify-email.tsx`) with the copy and a Log out button.
- [ ] **Step 4: Run** the full feature suite (the middleware touches every signed-in route). Expected: PASS.
- [ ] **Step 5: Commit**: `feat(tutors): pending and rejected tutors wait for approval`.

### Task 6: Public tutor sign-up

**Files:**
- Create:
  - migration `tutor_requested_classes` (id, tutor_id FK users cascade, class_group_id FK cascade, subject_id FK cascade, timestamps, unique on all three);
  - model `TutorClassRequest`;
  - `App\Support\FormTimer`;
  - `App\Http\Controllers\TutorApplicationController` (create, store);
  - `App\Http\Requests\TutorApplicationRequest`;
  - action `App\Actions\CreateTutorApplication`;
  - page `resources/js/pages/auth/tutor-join.tsx`.
- Modify: `routes/web.php` (guest routes `GET /tutors/join` `tutors.join`, `POST` `tutors.join.store` with `throttle:tutor-join`), `app/Providers/FortifyServiceProvider.php` (rate limiter `tutor-join`: 5/min per IP), `resources/js/pages/auth/login.tsx` (link with the Global Constraints copy)
- Test: `tests/Feature/TutorSignUpTest.php`

**Interfaces:**
- Produces:
  - `FormTimer::issue(): string` (encrypted unix time) and `FormTimer::passed(?string $token, int $minimumSeconds = 3): bool`;
  - `User::requestedClasses(): HasMany<TutorClassRequest>`;
  - `TutorClassRequest` with `classGroup()`, `subject()`.
- The request validates:
  - `name`, `email` (lowercased, unique across all users including trashed), `phone` (required, max 20), password + confirmation (`PasswordValidationRules`);
  - `classes` (required array, min 1) of `{class_group_id: exists active class, subject_id: exists active subject}`, distinct pairs;
  - honeypot `website` must be empty, and `form_started` must pass `FormTimer::passed`. A honeypot or timer failure gives the error "Something went wrong, please try again." on `form`.
- Store: create the user (role Tutor, approval_status pending, unverified), store the pairs, fire `Registered` (sends verification), log in, redirect to `verification.notice`.

- [ ] **Step 1: Write failing tests:**
  - `it('creates a pending tutor with their requested classes and sends a verification email')` (Notification fake);
  - `it('rejects a filled honeypot or a submit faster than 3 seconds')` (travel time to pass the timer in the happy path);
  - `it('rate limits sign-ups to 5 a minute')`;
  - `it('rejects inactive classes or subjects and duplicate pairs')`;
  - `it('rejects an email already used, including by a deactivated account')`;
  - `it('is reachable only by guests')`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** The page uses the auth layout like `auth/register.tsx`: name, email, phone, password + confirm; a "Classes you'll teach" list of rows, each a class select and a subject select with a remove button; "Add another class"; a hidden `website` input (`tabIndex={-1}`, `autoComplete="off"`, visually hidden, `aria-hidden`); hidden `form_started`; submit "Apply".
- [ ] **Step 4: Run** the task's tests, lint, types and build. Then do a browser check of `/tutors/join` at 1440 and 360 as a guest, without submitting. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(tutors): public tutor sign-up with bot protection`.

### Task 7: Admin review, approve and reject

**Files:**
- Create:
  - `App\Http\Controllers\Admin\TutorApplicationController` (index, show, approve, reject);
  - `App\Http\Requests\Admin\ApproveTutorRequest`;
  - action `App\Actions\ApproveTutorApplication`;
  - mail `App\Mail\TutorApprovedMail` + `resources/views/mail/tutor-approved.blade.php`;
  - pages `resources/js/pages/admin/tutor-applications/{index,show}.tsx`.
- Modify: `routes/web.php` (`admin.tutor-applications.index|show|approve|reject`), `resources/js/pages/admin/users/index.tsx` (a "Pending tutors (N)" link/tab to the index), `app/Http/Middleware/HandleInertiaRequests.php` (share `pendingTutorCount` for admins only), `app-sidebar.tsx` (count badge on the admin Users item)
- Test: `tests/Feature/Admin/TutorApplicationsTest.php`

**Interfaces:**
- Consumes: `ClassSlot::overlapping` (Task 4), `LessonScheduler` (Task 2), `ApprovalStatus` (Task 5), `TutorClassRequest` (Task 6).
- Produces:
  - **index:** verified pending tutors, oldest first, with a status filter `pending|rejected`.
  - **show:** applicant details plus `rows`. For each requested pair, list each **non-deleted slot** of that class and subject (`{slotId, timeLabel, currentTutor: string|null}`), or `slots: []` when none exist.
  - **approve:** payload `decisions: array<{request_id:int, slot_id:int|null, action:'assign'|'handover'|'leave'|'create'|'skip', weekday?:int, start_time?:'HH:MM', duration_minutes?:int, location?:string|null}>`. One decision per slot listed, and one per request with no slots.
    - Validation:
      - `assign` only for a slot with no tutor;
      - `handover` and `leave` only for a slot with a tutor;
      - `create` needs weekday, start and length, and passes the overlap rule;
      - every request must be covered.
  - `ApproveTutorApplication::handle(User $tutor, array $decisions): void` runs in one transaction:
    - `assign` and `handover`: set the slot tutor, then `rescheduleFuture`;
    - `create`: a new slot, then `generateFor`;
    - set approved, then send `TutorApprovedMail` after commit.
  - **reject:** set rejected. Approve and reject both work from pending **or** rejected.

- [ ] **Step 1: Write failing tests:**
  - `it('lists only verified pending applications, oldest first')`;
  - `it('shows each requested class and subject with its slots and current tutors')`;
  - `it('hands a slot over, moving only future unattended lessons to the new tutor')`: the next lesson has attendance and stays with the old tutor;
  - `it('leaves a slot with its tutor when asked')`;
  - `it('assigns an untaught slot and creates a new slot, generating lessons')`;
  - `it('refuses a created slot that overlaps')`;
  - `it('approves with everything skipped')`;
  - `it('emails the tutor once approved')` (Mail fake, subject "You're approved");
  - `it('rejects an application and can approve it later')`;
  - `it('shares the pending count with admins only')`;
  - admin-only 403s.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** On the show page, each requested pair is a card with a radio group per slot:
  - "Hand over to {name}" / "Leave with {current}";
  - "Assign to {name}" / "Skip";
  - with no slot, "Create a slot" (day, start, length, room) / "Skip".
  - Approve (primary) and Reject (outline, with a confirm).
- [ ] **Step 4: Run** the task's tests, the full feature suite, lint, types and build. Then do a browser check as admin of the index and show pages at 1440 and 360, without approving or rejecting. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(admin): review, approve and reject tutor applications`.

### Task 8: Tutor "My classes" and adding students

**Files:**
- Create:
  - `App\Http\Controllers\Tutor\ClassController` (index);
  - `App\Http\Controllers\Tutor\StudentController` (create, store);
  - `App\Http\Requests\Tutor\StoreStudentRequest`;
  - `App\Policies\ClassGroupPolicy` (`addStudent`);
  - pages `resources/js/pages/tutor/classes/index.tsx` and `resources/js/pages/tutor/students/create.tsx`.
- Modify: `routes/web.php` (role:tutor group: `tutor.classes.index` GET `/my-classes`; `tutor.classes.students.create|store` under `/my-classes/{classGroup}/students`), `app-sidebar.tsx` (tutor item "My classes", icon `classes`, after Dashboard)
- Test: `tests/Feature/TutorStudentsTest.php`

**Interfaces:**
- Consumes:
  - `ClassGroup::scopeTaughtBy` (Task 2);
  - `CreateStudentAccount::handle(array $data, ?User $guardian = null, ?string $relationship = null): User`;
  - `ClassGroup::enrol(User $student)`;
  - `AccountValidationRules`.
- Produces: `ClassGroupPolicy::addStudent(User $user, ClassGroup $classGroup): bool`, true for a tutor teaching a non-deleted slot of that active class.

- [ ] **Step 1: Write failing tests:**
  - `it('lists the tutor\'s classes with their slots and active students')`;
  - `it('adds a student to a class the tutor teaches')`: the user has role student, the profile year group defaults to the class's, the student is enrolled, and a given email is verified;
  - `it('enforces username and email rules')`;
  - `it('refuses classes the tutor does not teach')` → 403 on GET and POST;
  - `it('is not available to admins, students or guardians through these routes')`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
  - **My classes:** a card per class, with the class name and year badge, the tutor's slots in it ("Mon 09:00–11:00 · Maths"), the active students (avatar and name), and an "Add student" button.
  - **Add student form:** name, username, password, year group (defaulting to the class's), optional email.
  - After saving, return to My classes with the toast "{name} added to {class}."
- [ ] **Step 4: Run** the task's tests and the full feature suite, then lint, types and build. Then do a browser check as `tutor.adaeze@academy.test`: My classes and the Add student form at 1440 and 360, without submitting. Expected: PASS.
- [ ] **Step 5: Commit**: `feat(tutors): my classes and adding students`.
