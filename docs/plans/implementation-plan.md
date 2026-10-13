# Implementation Plan (Roadmap) — Jerry Great Academy Platform

**Specs:** [design-brief.md](../design/design-brief.md) · [design-system.md](../design/design-system.md) · [database-schema.md](../architecture/database-schema.md)

**Stack:** Laravel 13 · PHP 8.4 · Inertia v3 · React 19 · Tailwind v4 · Wayfinder · Pest 5 · SQLite (local)

The project is split into phases. Each phase ships working, tested software and gets its own detailed task-by-task plan in `docs/superpowers/plans/` just before it starts. Phase 1 is already written in detail: [2026-10-13-phase-1-foundation.md](../superpowers/plans/2026-10-13-phase-1-foundation.md).

## Decisions needing approval (blocking where noted)

| #   | Decision                                                               | Recommendation                                                                                                                                                                                                                       | Blocks  |
| --- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- |
| D1  | **Auth package** (this is a blank starter kit, so there's no auth yet) | Add `laravel/fortify` (headless; we build the React screens with our design system)                                                                                                                                                  | Phase 2 |
| D2  | **Payments**                                                           | ✅ **Paystack and Flutterwave (pinned to the stable v3 API)**, both behind one `PaymentGateway` contract and switchable through `PAYMENT_GATEWAY`. No new packages (the Laravel `Http` client); one monthly subscription per student | Phase 6 |
| D3  | **Maths rendering**                                                    | Add `katex` (npm) for fractions, indices and so on in question stems                                                                                                                                                                 | Phase 5 |
| D4  | **Who takes the register?**                                            | Tutors mark students; tutors check themselves in; admins can override anything                                                                                                                                                       | Phase 3 |
| D5  | **Lateness grace period**                                              | 0 minutes by default, configurable                                                                                                                                                                                                   | Phase 3 |
| D6  | **Question bank source**                                               | Authored in-house, optionally drafted with AI, always human-reviewed. Import via CSV                                                                                                                                                 | Phase 5 |
| D7  | **Price and trial**                                                    | ✅ Currency is **NGN**. Client to confirm the monthly ₦ amount and whether there's a free trial. Plans are created in the Paystack and Flutterwave dashboards with that amount                                                       | Phase 6 |
| D8  | **Git**                                                                | The folder isn't a git repo yet. Run `git init` before Phase 1 so each task can commit                                                                                                                                               | Phase 1 |

## Phase 0: Design system ✅ (done)

- Brand tokens (royal indigo `#1B0063` and crown gold `#E49C24`), semantic, status, difficulty and strand tokens with dark mode, in `resources/css/app.css`.
- Fonts: Lexend (UI) and Saira Condensed (display).
- Primitives: `Button`, `Card*`, `Badge`, `Input`, `Label`; domain components `AppLogo`, `AttendanceStatusBadge`, `DifficultyBadge`.
- Local-only `/styleguide` preview page.

## Phase 1: Domain foundation (no new dependencies)

Enums, roles on users, student profiles, guardian links, the year → difficulty rule, factories with role states, and seeders. → [detailed plan](../superpowers/plans/2026-10-13-phase-1-foundation.md)

## Phase 2: Accounts & app shell (D1 ✅ Fortify; students log in with a username)

Split into **2a**, auth and app shell ([detailed plan](../superpowers/plans/2026-10-13-phase-2a-auth-shell.md)), and **2b**, guardians adding children, admin user management, and `softDeletes` on users (deferred from Phase 1).

- Fortify: login, logout, password reset, email verification. Registration is **guardian self-signup only**; admins create tutors and students.
- Guardians can add their children (each child becomes a student user, with optional login credentials).
- `EnsureRole` middleware plus policies; role-based redirects after login.
- App shell: sidebar or top nav per role (Admin, Tutor, Student, Guardian), `AppLayout`, flash toasts, empty states, skeletons.
- Admin user management (CRUD for tutors and students).
- **Tests:** each role lands on its own dashboard, cross-role access returns 403, and guardians only see their own children.

## Phase 3: Attendance & punctuality (needs D4, D5)

- `ClassGroup` and enrolment CRUD (admin), and a `GenerateLessons` command that creates the next 4 weeks from each weekday/start time (scheduled daily).
- Tutor **Today** screen: a check-in button (creates the tutor's own `AttendanceRecord` with `arrived_at = now()`), then the register (On time / Late + time / Absent).
- A `RecordAttendance` action that computes `minutes_late`, sets the status, and creates, updates or deletes the matching `LatenessReport` in one transaction.
- Admin override on any record; a cron job marks no-shows absent 15 minutes after a lesson ends.
- **Tests:** a tutor late by 1 minute gets a report, students and tutors are treated the same, correcting a record to on time removes its report, there's at most one record per person per lesson, and a tutor can't mark another tutor's lesson.

## Phase 4: Reports

- **End-of-class reports:** the tutor and every enrolled, present student can each submit one per lesson (same form, same fields), and can edit it within 48 hours.
- **Lateness reports:** an admin list with filters (role, person, class, date range), a "repeat lateness" view (≥3 in 30 days), review/follow-up notes, and CSV export.
- Guardian view of their child's attendance history and lateness.
- **Tests:** duplicate submission is rejected, an absent student can't submit, the repeat-lateness threshold, and a guardian can only see their own child.

## Phase 5: Quiz platform core (needs D3, D6)

- Seed `strands` and the KS2/KS3/GCSE `topics` (National Curriculum list).
- Admin question bank: CRUD with a preview, the draft → review → published workflow, exam-track tagging, and CSV import.
- Quiz recipes (year group, strand/topic, exam track) and a `QuestionPicker` service (unseen first, then least recently seen).
- Student quiz player: one question per screen; MC, multi-select, numeric (with tolerance) and short text; server-side marking; a results page with explanations.
- Student home: "My year" quizzes at the default difficulty, strand tiles, exam prep (11+, SATs, GCSE), and the Challenging tier once it's unlocked.
- **Tests:** no repeats until the pool is used up, then least-recent first; unpublished questions are never served; marking for each question type; a student can't submit someone else's attempt; the answer key never reaches the client before submission.

## Phase 6: Subscriptions — Paystack + Flutterwave (needs D2, D7)

- `plans`, `subscriptions`, `payments` and `webhook_events` tables and enums, plus `config/payments.php` (see schema §6). Money is stored as integer **kobo**, in NGN only.
- **Admin → Billing settings:** paste the Paystack plan code and the Flutterwave plan id from each dashboard. On save, the app fetches the plan from the gateway and rejects it if the amount, `NGN` or `monthly` don't match. `PlanSeeder` reads the sandbox codes from `.env`.
- The `PaymentGateway` contract, `PaymentGatewayManager`, and the `PaystackGateway` and `FlutterwaveGateway` drivers (REST over `Http`, no SDKs). The Flutterwave driver targets **v3 only** and converts kobo ↔ naira at its edge; the Paystack driver uses kobo directly.
- `StartCheckout` action → a hosted checkout redirect; `/billing/callback/{gateway}` verifies on the server.
- Webhooks at `/webhooks/paystack` (HMAC-SHA512 signature) and `/webhooks/flutterwave` (`verif-hash`). They're stored idempotently in `webhook_events`, then `ApplyGatewayEvent` runs on a queue.
- Cancel (stop at the period end), Paystack's manage-card link, and an hourly `subscriptions:expire` command.
- Guardian billing page: one row per child showing status, renewal date, gateway, and Subscribe/Cancel.
- `EnsureQuizAccess` middleware on every quiz route; a paywall page for anyone without access.
- **Tests (all with `Http::fake()` and recorded payload fixtures, run for both drivers through a Pest dataset):**
    - **amounts:** ₦5,000 is sent as `500000` to Paystack and as `5000` to Flutterwave, and a Flutterwave webhook for `5000` is stored as `500000` kobo; a verified payment whose amount or currency (anything but `NGN`) doesn't match the plan is rejected;
    - Billing settings rejects a plan code that's unknown, inactive, non-NGN, non-monthly or the wrong amount;
    - with no plan linked, checkout shows "Payments unavailable" instead of erroring;
    - a verified checkout activates the subscription; a callback with a failed or tampered reference doesn't;
    - a webhook with a bad signature or hash gets 401 and changes nothing; a duplicate webhook is a no-op;
    - the callback and the webhook in either order give the same final state;
    - a renewal extends the period; a failed renewal sets `PastDue` and keeps access through the grace period, then it's `Expired`;
    - cancelling keeps access until the period ends, and the cancel goes to the subscription's own gateway even after `PAYMENT_GATEWAY` changes;
    - a guardian can't subscribe for, or cancel, another family's child; tutors and admins always have access.

## Phase 7: Progress & analytics

- Student progress: per-strand accuracy, streaks, and a recent-attempts chart.
- Guardian dashboard: each child's quiz progress plus attendance.
- Admin: question quality stats (`times_correct / times_served`), low-pool warnings, active subscriptions.

## Phase 8: Hardening & launch

- Accessibility pass (axe, keyboard-only run-through), performance (indexes, eager loading), backups.
- Laravel Cloud deployment with a MySQL/Postgres DB, a queue worker, the scheduler, and Paystack/Flutterwave live keys with their webhook URLs registered in each dashboard.
- UAT with the client: a test register, a test quiz, and a test subscription on **each** gateway in test mode.

## Cross-cutting rules

- Pest feature tests for every endpoint, run via `php artisan test --compact`.
- Pint runs on every PHP change, and `npm run check` on every TS change.
- Use Wayfinder imports (`@/actions`, `@/routes`); never hard-code URLs.
- Use named routes, form requests for validation, policies for authorisation, and an action class for each domain write.
