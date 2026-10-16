# Tutor dashboard, register entry and end-of-class reports

**Date:** 2026-10-16
**Status:** Draft for approval
**Mockups:** the three tutor screens shared in the 2026-10-16 conversation (dashboard, register, end-of-class report).

## Why

Tutors currently land on a bare "Today" page. The academy wants tutors to have one dashboard that gets them to their class on time, shows how their classes are going, and keeps end-of-class reports from slipping. Reports matter because they are the admin team's main view of what happens in class, and tutors sometimes forget to write them.

## Goals

- A tutor dashboard that matches the mockup: next-class banner with check-in, four figures, this week's classes, and "Students to watch".
- A tutor sidebar of **Dashboard**, **Take the register** and **End-of-class report**.
- An end-of-class report page where a tutor reports on the current class **or any earlier class they forgot to report on**, with a star rating, two text answers, recommendation chips, a free-text recommendation, a "Raise an issue with the academy" flag, and Save draft / Submit.
- The register page (rebuilt 2026-10-16) gets the mockup's tutor header.

## Non-goals

- **Cohorts / terms.** Classes are sometimes in a cohort (e.g. a summer programme) and sometimes ordinary weekly classes. Modelling cohorts is a separate future feature. Until then, every "this term" figure in the mockup is a **rolling last 90 days**, and is labelled that way.
- **Tutors reading student reports.** The mockup's Tutor / Student switch is dropped. Students keep writing their reports, and only admins see them, as now. This avoids friction between tutors and students.
- **A count badge** on the End-of-class report menu item (dropped by the user).
- **Low quiz scores** in Students to watch. The user named high quiz scores and absenteeism only.

## Anti-goals (things that would make this a failure even if it works)

- A tutor seeing another tutor's classes, students or reports, or any student report.
- A draft report reaching admins, or counting as submitted anywhere.
- Students to watch reading as an action list or a judgement on the student. It is a heads-up only.

## Constraints

- Follow the project's existing patterns: services in `app/Services` for read models (as `AdminDashboard`, `GuardianDashboard`), actions for writes, policies for authorisation, Wayfinder routes, Pest feature tests, the existing UI primitives (`Card`, `Badge`, `StatCard`/totals cards, `PersonAvatar`, `AttendanceStatusBadge`).
- All times are academy-local (`config('academy.timezone')`), as everywhere else.
- Local database: migrate only, never fresh/reseed.
- "Last 90 days" means lessons whose start falls in the 90 days up to now.
- **Automatic absences don't count as evidence** *(my call)*. `attendance:mark-no-shows` writes an Absent record (`recorded_by_id` null) for everyone left unrecorded after a lesson, including when the tutor simply never took the register. Students to watch and the student attendance figure use only attendance records a person entered (`recorded_by_id` not null). This matches `ClassReportInbox`, which already ignores absent-only lessons.
- **"Needs a tutor report"** *(my call)* means one of the tutor's lessons that has started, isn't cancelled, has no submitted tutor report, where no person has marked the tutor Absent or Excused (an automatic no-show absence, written when the tutor forgot to check in, doesn't count), and where at least one person attended (the tutor's check-in counts). The admin "Awaiting tutor report" queue uses the same rule, so the two stay in step. The report policy refuses the excluded lessons for tutors.

## Decided behaviour

### Sidebar (tutor)

Dashboard · Take the register · End-of-class report. No badges.

- **Take the register** opens the register for today's current or next lesson (not cancelled). On a day with no lessons, it opens the tutor's most recent past lesson. With no lessons at all, it returns to the dashboard with a toast saying there's nothing to open. *(My call; the user didn't object.)*
- **Viewing vs editing a register** *(my call)*: a tutor may **view** the register of any of their own non-cancelled lessons at any time. Outside the existing editing window (30 minutes before the start to a day after the end), it is read-only: the counts and each student's saved status show, but the buttons, "Mark everyone unmarked as on time" and Save are not offered, with a line explaining when it opens or that it has closed. The editing window and the server-side save rules are unchanged; admins are unaffected.
- **End-of-class report** opens the report page (below) on its default class.

### Dashboard

Top to bottom, as in the mockup:

1. **Header.** Today's date in small caps, "Good morning/afternoon/evening, {name}", and "Now HH:MM" at the right.
2. **"From the academy".** The existing card of notes an admin has raised with this tutor about a lesson (`RaisedIssue`), kept as it works today. It goes above the banner and only shows when there are unread notes.
3. **Next class banner** for today's current or next lesson:
   - "NEXT CLASS · STARTS IN N MINUTES" (or "IN PROGRESS" once started), class name, "16:00 – 17:00 · Room 2 · 12 students".
   - Before check-in: **Check in to start the class** (the existing check-in action and its time window), with "Your arrival time is recorded for punctuality."
   - After check-in: the button becomes **Open register**, and the line reads "Checked in 15:56 · on time" (or "· N min late").
   - No lesson left today: the banner names the next lesson on a later day without a check-in button. With none at all, it says no classes are scheduled.
4. **Four figures:**
   - **My punctuality · last 90 days:** the share of the tutor's attended lessons (their own record Present or Late) where they were on time. Hint: "On time for 71 of 72 classes". Shows "—" with no attended lessons.
   - **Classes this week:** the tutor's non-cancelled lessons Monday to Sunday this week. Hint: "3 done · 5 to go" (done = ended).
   - **Reports to submit:** the tutor's lessons that need a tutor report (see Constraints; drafts count as not submitted), however old. Hint names the oldest: "Year 5 · Geometry (Monday)". "All caught up" at zero.
   - **Student attendance · last 90 days:** across the tutor's lessons, students present or late ÷ (present + late + absent), using person-entered records only. Excused records are ignored, matching `AttendanceStats`.
5. **This week's classes** (Monday to Sunday), one row per lesson: day (or "Today") and time, class name, then:
   - Past: "12 of 12 present · you were on time" ("you were N min late" / "you didn't check in"), or "Register not taken" when no person-entered student records exist. Then **Report submitted** (a tick and a link to view or correct it), **Finish report** (draft exists), **Write report**, or nothing when the lesson doesn't need a tutor report.
   - Today and not yet ended: the row is highlighted, with "Starts in N minutes" or "In progress", and **Open register**.
   - Later: "N students" and "Upcoming".
   - Cancelled: "Cancelled".
6. **Students to watch** (see below).

The clock and "starts in N minutes" may tick client-side; the builder decides.

### Students to watch

A read-only heads-up: no actions, nothing to resolve. Candidates are the active students in the tutor's active classes. Only person-entered attendance records count. A student is listed when one or more of these is true, with every reason that applies on one line:

- **Absent from their most recent marked lesson** (most recent lesson with a person-entered record for them) in this tutor's classes: "Absent last class". If the register note for that lesson isn't empty, it follows: "Absent last class · asked for quieter seating".
- **Late two or more times** in the tutor's lessons in the last 90 days: "Late twice in 90 days" / "Late 3 times in 90 days".
- **Absent two or more times** in the last 90 days (not counting the case above twice): "Absent 3 times in 90 days".
- **Doing well in a topic:** at least three submitted quiz attempts in the last 90 days on quizzes in one strand, averaging 85% or more. Line: "Geometry quizzes 92% — ready for Hard", naming the difficulty after the student's year default (`YearGroup::defaultDifficulty()`), or "— ready for harder questions" when there is no next level or no default.

Each entry shows avatar, "Name · Year N" and the reasons. Up to five students: attendance concerns first, then high performers, each group ordered by strength of signal (the builder decides the exact ordering). With nobody to flag: "Nobody to flag right now."

### Register page (tutor header)

On top of the 2026-10-16 redesign:

- A "← Dashboard" back link above the title.
- The subtitle reads "Tuesday 13 October · scheduled 16:00 – 17:00 · Room 2".
- For the lesson's tutor, the separate Tutor card is replaced by a badge at the top right: "You checked in 15:56 · on time" ("· N min late"), or "You haven't checked in" with the check-in button when the window allows. Admins keep the editable tutor row as now.

### End-of-class report page

- **Class picker:** the tutor's lessons that need a tutor report (see Constraints; drafts included), newest first, however old. The lesson being viewed is always in the list, even if already submitted (so a submitted report can be opened and corrected from the dashboard). Choosing a class moves to that lesson's report.
- **Default class** (from the sidebar): the current or most recently ended lesson if it still needs a report, otherwise the newest lesson that still needs one. With nothing outstanding: "You're all caught up", plus a link to the dashboard.
- **Tutor report window:** a tutor may write or correct their report on any of their lessons that needs a tutor report, or that they've already submitted a report for, with no deadline. Students keep the current 48-hour window.
- **How well did the lesson go?** Five stars with a word for the chosen score: 1 Poor, 2 Below par, 3 OK, 4 Good, 5 Excellent. Required to submit.
- **What went well?** and **What could be better?** are the existing `went_well` and `issues` fields, with the mockup's placeholders.
- **Recommendations:** chips to toggle, in this order: Set a follow-up quiz · Move up a difficulty level · Extra practice on this topic · Speak to a parent · Recap at the start of next class. Plus an **Another recommendation** text field (the existing free-text `recommendations`).
- **Raise an issue with the academy** (checkbox): "Flags this report for the admin team to follow up." A submitted flagged report appears in the admin Class reports "needs review" queue with a visible flag, and counts as needing attention.
- **Save draft** stores everything without validation beyond field lengths, private to the tutor. **Submit report** validates (rating required) and stamps the submission. A submitted report can be edited and re-submitted; it can't go back to being a draft, so **Save draft is not offered once a report is submitted** *(my call)*. Re-submitting stamps a new `submitted_at`, so a corrected report returns to the admin "needs review" queue the same way it does today.
- **"This class" panel:** date, time, tutor, attendance ("9 of 10 present", or "Register not taken" when no person-entered student records exist), and "Your report is shared with the academy admin team."
- After submitting: back to the dashboard with the existing toast.

### Data changes

- `lesson_reports.submitted_at` becomes nullable. A null value means a draft. Every existing reader (admin inbox, counts, dashboards, the student report flow) must treat only rows with `submitted_at` as reports.
- `lesson_reports.recommended_actions` (JSON array of chip keys, nullable).
- `lesson_reports.raises_issue` (boolean, default false).

## Left to the builder

- Chip keys and how they're stored and validated (the labels above are fixed).
- Whether the report picker uses a select (as mocked) or another control, as long as it works on phones.
- How the dashboard is split into services and components, and query shapes (keep them free of N+1; tutors may have dozens of lessons in 90 days).
- Exact ordering inside Students to watch, within the rules above.
- Phone layout (the mockups are desktop only): stack sensibly with a 16px gutter and no horizontal page scroll.

## Testing focus

- A tutor sees only their own lessons, students and reports. They can't open another tutor's report or register, and never see student reports.
- Drafts never appear to admins or in counts, and a draft doesn't satisfy "Reports to submit".
- Tutors can report on old lessons; students still can't after 48 hours; nobody can report on a cancelled or unstarted lesson; tutors can't report on lessons they missed or that nobody attended.
- Automatic no-show absences don't affect Students to watch, the student attendance figure or "Register not taken".
- A tutor can view, but not change, a register outside the editing window.
- Each figure and Students to watch rule, at its boundary (90 days, two lateness records, three quizzes, 85%).
- Take the register routing in each case (current lesson, later today, none today, none at all).
