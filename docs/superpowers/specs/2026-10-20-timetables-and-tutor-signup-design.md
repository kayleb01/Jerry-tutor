# Class timetables, subjects and tutor sign-up

**Date:** 2026-10-20
**Status:** Draft for approval

## Why

Tutors are members of the academy's team. When someone new joins, an admin wants to send them to the app to sign up themselves, instead of the admin typing their details in. A class is a group of students taught by **several tutors, each teaching their own subject at their own time**. For example, Year 5 has Maths with Mr Bello on Monday 09:00–11:00 and Science with Ma Rukayya on Monday 12:00–14:00. Today the app allows one tutor and one weekly time per class, so it can't describe the academy's real timetable. Once approved, tutors also need to add students to their classes without waiting for an admin.

## Goals

1. **Subjects:** a list admins manage (Maths, English, Science…).
2. **Class timetables:** a class has any number of weekly **slots**, each with a subject, a tutor, a day, a start time, a length and a room. Lessons, registers, check-in and end-of-class reports belong to a slot's lesson and its tutor.
3. **Tutor sign-up:** a public page where a new tutor gives their name, email, phone and password, and picks one or more **class + subject** pairs they'll teach. They verify their email, then wait for an admin to approve them.
4. **Approval:** admins see verified applications, and for each class + subject pair either assign the new tutor or leave it, then approve or reject.
5. **Tutors add students:** an approved tutor can create student accounts straight into a class they teach.

## Non-goals

- Qualifications or CVs on sign-up (the user said not needed).
- Tutors linking or creating parent accounts (left to admins).
- Tutors editing, deactivating or moving students, or enrolling existing students (admin jobs, as today).
- Two slots of one class at the same time (the same students can't be in two places).
- A CAPTCHA service (possible later; no outside accounts now).
- Invite links (the user chose an open page with approval as the gate).

## Anti-goals

- An unapproved or rejected tutor seeing any student, class, register or report.
- Existing classes, lessons, registers, reports or attendance changing meaning after the migration.
- Bots filling the admins' pending list.

## Constraints

- Existing data is the user's: migrations must carry every existing class into the new shape with no loss. Never use fresh migrations or reseeding locally.
- Account invariants still hold:
  - usernames match `^[a-z0-9._-]+$`;
  - emails are lowercased;
  - `role` is never fillable and never changes;
  - users are soft-deleted;
  - accounts created by a trusted person get `email_verified_at` set when they have an email.
- Lessons keep storing their own `tutor_id`, `starts_at` and `ends_at` (a snapshot). Everything downstream (policies, dashboards, reports, attendance) keeps reading `lessons.tutor_id`.
- Academy timezone for all times, as everywhere else.

## Decided behaviour

### Subjects

- `subjects`: name (unique), `is_active`, `sort_order`.
- Admin page **Subjects**: list, add, rename, deactivate. A deactivated subject can't be picked for new slots or sign-ups, but existing slots keep it.
- The migration creates **Maths** *(my call; it's what the app teaches today)*.

### Class timetables

- A **class** keeps its name, year group, active flag and students. Its single tutor, day, start time, length and room move to a first **slot**.
- `class_slots`:
  - `class_group_id`, `subject_id`;
  - `tutor_id` (nullable: a slot with no tutor generates no lessons, as a class with no tutor does today);
  - `weekday`, `start_time`, `duration_minutes`;
  - `location` (nullable).
- **Migration:** every existing class becomes one slot with subject Maths and the class's current tutor, day, time, length and room. Existing lessons get that slot's id. Then the moved columns are dropped from `class_groups`. *(The room moves to the slot because different subjects often use different rooms; my call.)*
- **Unscheduled classes** *(my call)*: a class with no day or time today still becomes one slot, keeping its tutor, with `weekday` and `start_time` left empty. Such a slot generates no lessons (as an unscheduled class does now) and is ignored by the overlap rule. Its tutor still counts as teaching the class.
- **Removing a slot** *(my call)* soft-deletes it. Its future unattended lessons are deleted; past and attended ones keep pointing at it, so their titles still resolve. Deleted slots are ignored by generation, the overlap rule, "a tutor's classes" and the approval page. Titles are derived live, so renaming a subject renames old lessons too.
- **Lessons** gain `class_slot_id` (required after the migration). Generation runs per slot, copying the slot's tutor and times as it does now from the class. Editing a slot reschedules that slot's future lessons, using the existing rules: only future, scheduled, unattended lessons are rebuilt.
- **No overlapping slots in one class:** saving a slot whose time overlaps another slot of the same class on the same day is refused ("Year 5 already has Maths 09:00–11:00 on Monday").
- **Lesson title** everywhere a lesson is named (registers, reports, dashboards, attendance, admin reports, CSV): "{class} · {subject}", e.g. "Year 5 · Science". Class-level pages keep the class name.
- **A tutor's classes** = classes where they teach at least one slot. **A tutor's students** = the active students of those classes. This is used by Students to watch, tutor student creation, and the admin dashboard tutor filter.
- **Deactivating a tutor** clears them from their slots and reschedules, as it clears the class tutor today.
- **When a slot changes tutor** (an edit, a handover at approval, or a deactivation) *(my call)*: only future, scheduled, unattended lessons are rebuilt with the new tutor. Past lessons, lessons with any attendance, and their reports and drafts stay with the tutor who taught them. This is the existing rule, so no reassignment step is needed. The previous tutor isn't notified.
- **Rescheduling a slot** may delete its old future lessons before regenerating them inside one transaction, so moving a slot onto a time another slot just vacated works. The overlap check uses the new times.
- **Admin class page:** class details (name, year group, active) plus a **Timetable** section listing slots, with Add / Edit / Remove. A slot form takes subject, tutor, day, start, length and room. Removing a slot deletes its future unattended lessons and keeps past ones.

### Tutor sign-up

- **Page:** `/tutors/join`, public. Linked from the login page ("Joining as a tutor? Apply here"). The existing `/register` stays the parent sign-up.
- **Fields:**
  - name, email, phone (required), password + confirmation;
  - **"Classes you'll teach"**: one or more rows of *class* (active classes) + *subject* (active subjects), add or remove rows, at least one row, no duplicate pairs.
- **Bot protection (all three):**
  1. A hidden honeypot field that must stay empty, plus a minimum time between the page loading and submitting (3 seconds, carried in a signed or encrypted value). A failure fails quietly with a generic error.
  2. Rate limit: 5 attempts a minute per IP, like the parent sign-up.
  3. Email verification: the account is created unverified and Fortify's verification email is sent. **An application only appears to admins once the email is verified.**
- **Email uniqueness** *(my call)*: unique across all users, including deactivated ones, like the parent sign-up. A rejected applicant can't re-apply with the same email. Instead, an admin can open a rejected application from the Pending tutors tab's "Rejected" filter and approve it later. Unverified applications are never cleaned up automatically.
- **Result:**
  - A user with role Tutor and `approval_status = pending` is created, with the requested pairs stored in `tutor_requested_classes` (tutor_id, class_group_id, subject_id).
  - They are logged in and sent to verify their email.
  - After verifying, they see **"Thanks — your application is with the academy team. We'll email you when it's approved."**

### Approval states

- `users.approval_status`: `pending` | `approved` | `rejected`. Null for non-tutors.
  - The migration sets every existing tutor to `approved`.
  - Tutors created by an admin on the Users page are `approved` straight away.
- An `approved.tutor` middleware on the whole signed-in route group *(my call)*. It only acts when the user is a tutor. Logout, email verification and the waiting/rejected page stay reachable. Otherwise:
  - **pending:** the waiting page above;
  - **rejected:** "Your application wasn't approved. Please contact the academy." with Log out.
  - Neither can reach any other page.
- On approval, the tutor gets an email: "You're approved — you can now log in and see your classes." *(My call: they're told to wait for an email, so one is owed.)*

### Admin approval

- The **Users** page gains a **Pending tutors** tab, showing verified pending applications only, oldest first. The admin sidebar's Users item shows a count badge while any exist *(my call)*.
- **Review page per applicant:** name, email, phone, applied date, and one row per requested pair:
  - **The class has a slot for that subject with a tutor:** "Year 5 · Science — taught by Ma Rukayya (Mon 12:00–14:00)". Choose **Hand over to {applicant}** or **Leave with Ma Rukayya**.
  - **The class has a slot for that subject with no tutor:** choose **Assign** or **Skip**.
  - **No slot for that subject:** **Create a slot** (day, start, length, room inputs, with the overlap rule applied) or **Skip**.
  - If a class has several slots of the same subject, each one is listed and decided separately.
- **Approve** applies every decision in one transaction:
  - assigning or handing over sets the slot's tutor and reschedules that slot's future lessons;
  - creating a slot generates its lessons;
  - then status becomes approved and the email is sent.
  - Approving with every row skipped is allowed; the tutor then has no classes until an admin adds them to a slot.
- **Reject** sets `rejected`. Nothing is assigned.

### Tutors add students

- New tutor page **My classes** (sidebar, after Dashboard): each of the tutor's classes with its year group, their slots in it, and its active students.
- **Add student** on a class opens a form:
  - name, username, password, year group (defaults to the class's year group), optional email;
  - the same rules and the same `CreateStudentAccount` action an admin uses, so a given email is trusted and verified.
  - The student is enrolled in that class.
- A tutor can add students only to classes they teach a slot in. Anything else gets a 403.

## Left to the builder

- How the timetable editor looks (list plus modal or inline form), and the exact mobile layouts.
- How the signed minimum-time token is built.
- Query shapes and indexes. Keep the existing unique lesson index meaningful: unique `(class_slot_id, starts_at)` (and `(class_group_id, starts_at)` can stay, since slots can't overlap).
- Where to put the lesson-title helper, as long as there is one definition.
- Seeder and factory updates so demo data has a couple of multi-subject classes.

## Testing focus

- **Migration:** a class with tutor and schedule becomes one Maths slot, its lessons point at it, and nothing else changes.
- **Generation per slot:** two slots of one class make two lessons on the right days, each with its own tutor. Editing one slot reschedules only that slot. The overlap rule is enforced.
- **Sign-up:**
  - the honeypot, the too-fast submit and the rate limit each reject;
  - an unverified application doesn't show to admins, and a verified one does;
  - duplicate pairs and inactive classes or subjects are rejected.
- **Pending and rejected tutors** can't reach any tutor route or data.
- **Approval:**
  - handover moves only that slot's future, unattended lessons to the new tutor;
  - "leave" changes nothing;
  - creating a slot generates lessons;
  - skip does nothing;
  - the approval email is sent.
- **Tutor student creation:**
  - allowed for a class they teach, 403 otherwise;
  - the student is enrolled;
  - username and email rules hold.
- Existing tutor, register, report, attendance and admin tests keep passing, with lesson names now "{class} · {subject}".
