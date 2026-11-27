# Mobile quick actions and student check-in

**Date:** 2026-11-14
**Status:** Draft for approval

## Why

Feedback from a user going through the site: it needs too much explaining, and people should be able to "just use it". Most people will use the app on a phone. Today, everything on a phone is reached through the ☰ drawer, so nothing is one tap away. The feedback also asked that students mark their own attendance and see clearly what time they checked in.

This is the first of four pieces drawn from that feedback. The other three are tutors requesting a new class time or subject (admins approve it), assignments, and a richer parent progress view. Each will get its own design.

## Goals

1. **Quick-action bar on phones:** a fixed bar at the bottom of the screen with one big button for each everyday job, chosen per role.
2. **Student check-in:** a student checks in to their current class by typing a 4-digit code the tutor shows in the room. A large live clock shows the time, and on success it becomes a stamp: "Checked in at 09:03 · On time".

## Non-goals

- Redesigning the dashboards beyond adding the check-in entry point for students.
- Changing the desktop sidebar. The bar shows only below the `lg` breakpoint, where the drawer is used today.
- Geolocation, QR codes or rotating codes.
- Students correcting their own record after check-in.

## Anti-goals

- A student checking in to a class they aren't in, to a cancelled lesson, or outside the window.
- Guessing the code by trying many numbers.
- A student check-in overwriting something the tutor or an admin already recorded.
- The bar covering page content or the drawer.

## Constraints

- All attendance is written through `App\Actions\RecordAttendance`, the single place lateness, lateness reports and absence-notice clean-up happen. Student check-in calls it exactly as `TutorCheckInController` does: `Present`, arrival time now, `onlyIfUnrecorded: true`.
- The tutor's register stays the final say. Tutors and admins can change a self-check-in afterwards as they can any record today.
- Academy timezone for all times shown.
- Existing local data is the user's own. Migrations must be additive (no fresh migrations or reseeding).
- Wayfinder route functions for every link, as the existing sidebar does.

## Approaches considered (check-in trust)

1. **Tap to check in** inside the window, with the tutor's register as the safety net. This is the fastest, but a student could check in from home.
2. **Class code (chosen by the user).** The tutor's register shows a 4-digit code and the student types it. This proves the student is in the room, at the cost of one extra step.
3. **Unlock only after the tutor checks in.** This is cheap, but it doesn't stop a student checking in from home once class has started.

## Decided behaviour

### Quick-action bar

- **Shown on phones and tablets (below `lg`)**, fixed to the bottom of the screen, on every page that uses `AppLayout`. The bottom of `<main>` gets matching padding so nothing is hidden behind the bar. It respects the safe-area inset on iPhones.
- Each button is an icon over a short label, at least 44px tall, and highlighted on the current page in the same way as the sidebar's `isActive`. Sidebar badge counts also show on bar buttons that point to the same page.
- **More** opens the existing drawer (full menu, profile, log out). The ☰ button in the top bar stays.
- **Buttons per role:**

| Role | Buttons |
|---|---|
| Tutor (set by the user) | Home · Students · Attendance · Report · More |
| Student | Home · Check in · Tests · More |
| Parent | Home · Children · Billing · More |
| Admin *(my call)* | Home · Attendance · Users · Class reports · More |

  - Tutor: **Students** → `tutor.classes.index` (My classes, where students are listed and added). **Attendance** → `registers.next`. **Report** → `reports.next`.
  - Student: **Tests** → `quizzes.index`, shown only when the student has quiz access (the `quiz.access` rule). Otherwise the bar is Home · Check in · More. A self-paying student gets Billing in the drawer, as today.
- The tutor sidebar labels change to match the bar: "My classes" → "Students", "Take the register" → "Attendance", "End-of-class report" → "Report", so the two menus use the same words *(my call)*.

### Check-in code

- Each lesson gets a 4-digit numeric code (`lessons.check_in_code`, nullable, new migration). It's generated the first time it's needed (first shown to the tutor) and kept for the life of the lesson. Codes don't need to be unique across lessons.
- **Where the tutor sees it:**
  - at the top of the register page for that lesson, in large digits, with the line "Students check in with this code";
  - on the tutor dashboard's next-class banner when that lesson is today.

  It's shown only while students can check in (window below).
- Admins viewing a register see the code too.

### Student check-in

- **Window** *(my call, same as the tutor's opening)*: from 30 minutes before the lesson starts until the lesson ends. Lessons that are cancelled, or that the student isn't an active member of the class for, never offer check-in.
- **Check-in page** (`/check-in`, students only): picks the student's current lesson. That's the one in progress, else the next one starting within 30 minutes. Where two qualify, the earliest start wins.
  - **A lesson is open:** shows the lesson title ("Year 5 · Maths"), "Class starts 09:00", a large live clock (HH:MM:SS, academy time, ticking each second), a 4-digit code field (numeric keypad on phones) and a **Check in** button.
  - **Already recorded:** shows the stamp instead: "Checked in at 09:03 · On time" or "· 4 min late". If the tutor marked them and there's no arrival time, it shows that status ("Marked absent by your tutor").
  - **Nothing open:** "No class to check in to right now" and when their next class is ("Next: Tue 09:00 · Year 5 · Maths").
- **On submit:**
  - Wrong code: "That code isn't right. Check the number on your tutor's screen." Nothing is recorded.
  - Right code: recorded through `RecordAttendance` with the server's current time (never the client's), then the stamp is shown with a success toast. The arrival time and on-time/late status come from the saved record.
  - Already recorded: no change, and the stamp is shown.
- **Guessing limits** *(my call)*: 5 wrong tries per student per lesson in 10 minutes, then "Too many tries. Ask your tutor." until the limit clears. This uses Laravel's rate limiter keyed by student and lesson.
- **Student check-in does not change the lesson's status** *(my call)*. Only the tutor's own check-in moves a lesson to In progress, so a lesson never looks started with no tutor there.
- **Tutor's register:** a student who checked in themselves shows as present/late with their arrival time, plus a small "Self check-in" marker (`recorded_by_id` equals the student). The tutor can still change it.
- **Live register and safe save (chosen by the user):**
  - While a register is open and the lesson is in its check-in window, the page refreshes the student rows every 15 seconds (Inertia polling, partial reload of the register data only). Rows the tutor hasn't touched take the fresh value. Rows the tutor has edited keep the tutor's edit.
  - On save, the page sends when it loaded the register. If a student self-checked-in after that moment, the server keeps the check-in for that student, ignores the tutor's row for them, and flashes "Ada checked in at 09:03 while you were editing. Her check-in was kept." (one line listing each such student). Saving again from the refreshed page changes it on purpose, as normal.
- **Student dashboard:** while a lesson is open for check-in and the student hasn't checked in, a prominent card at the top reads "Year 5 · Maths starts at 09:00" with a **Check in** button to the check-in page. Once checked in, it shows the stamp.

## Left to the builder

- Component structure (for example a `QuickActionBar` component used by `AppLayout`), icon choices from the existing `Icon` set, and exact spacing within the design system.
- Whether the check-in page is a single controller with `show`/`store` or an invokable pair.
- How the code is generated (for example `random_int`, zero-padded).
- Test coverage, following `testing-best-practices`. At minimum: the window edges, wrong code, the rate limit, not-in-class, an already-recorded record, lateness computed from server time, and the code shown only to the lesson's tutor and admins.
