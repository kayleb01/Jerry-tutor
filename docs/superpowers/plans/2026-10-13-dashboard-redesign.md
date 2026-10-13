# Dashboard Redesign (Sidebar Shell + Student Dashboard) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every role gets the reference design's app shell: a dark sidebar, and a page header showing the user's name, a badge and a subtitle. The student dashboard also gets the reference's card layout, built from real data:

- stat cards;
- maths strand cards;
- today's activity;
- weekly activity, as a Kanban or list.

**Architecture:**

- **Shell:** `AppLayout` is rebuilt as a responsive sidebar shell, with a mobile drawer and a desktop collapse toggle remembered per browser. Nav items come from a per-role map.
- **Student data:** a `StudentDashboard` service builds the props from enrolments, lessons and attendance records. The server formats every number, date and time in the academy timezone.
- **Placeholders:** the quiz-dependent parts (strand progress, the next level) render empty states until Phase 5.
- **Icons:** inline SVGs (`Icon`). No new dependencies.

**Tech Stack:** Laravel 13, Inertia v3, React 19, Tailwind v4, Wayfinder, Pest 5.

**Spec:** the user's reference screenshot (2026-10-13), plus these decisions made with the user:

- the sidebar shell is for all roles, and the reference layout is for students;
- data is real wherever it exists, and quiz parts show empty states;
- keep royal indigo and gold.

Also [docs/design/design-system.md](../../design/design-system.md) and [docs/design/design-brief.md](../../design/design-brief.md) §4–6.

## Global Constraints

- Design-system tokens only (`bg-surface`, `text-muted-foreground`, `royal-*`, `gold-*`, status and strand tokens), plus `font-display` for the big numbers. Gold is only for achievement or attention accents (the trophy, "In progress"), at most one gold element per card.
- The sidebar is `bg-royal-950` with `text-royal-100`. The active item is `bg-royal-700 text-white`, with `aria-current="page"`. It must work in light and dark mode.
- **Mobile first.** Below `lg` the sidebar becomes a top bar with a menu button that opens a drawer (focusable, closes with Escape and on navigation). There's no horizontal scroll at 360px; the Kanban columns scroll horizontally inside their own container.
- **Real data only,** never made-up numbers. If something can't be computed yet (no records), show "—" with a short explanation. The quiz sections say they're coming in a later update.
- The server formats every displayed date and time in `config('academy.timezone')`. A week runs Monday to Sunday.
- **Accessibility:** landmarks (`nav`, `main`, `header`); icon-only buttons get `aria-label`; the Kanban/List toggle is a pair of `aria-pressed` buttons; badges always carry text.
- UK English, a friendly tone for students. Wayfinder imports only.
- Before each commit: `vendor/bin/pint --dirty --format agent`, then `npm run check:fix && npm run types:check`. Tests run with `php artisan test --compact <path>`.
- **Staging discipline** (memory `feedback-staging`): stage explicit paths, never `git add -A`, and check `git status` for files changed by someone else.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A student with no classes or no records**: every section renders an empty state with "—", with no division by zero and no null crashes. → Task 1 test.
2. **Week boundaries in the academy timezone**: a lesson at 00:30 Monday Lagos time (Sunday 23:30 UTC) belongs to Monday of the _new_ week. → Task 1 test.
3. **Withdrawn classes and cancelled lessons**: lessons from a class the student has left don't appear. Cancelled lessons show as "Cancelled" and don't count towards class time. → Task 1 test.
4. **Data leaks**: the student dashboard only ever includes the signed-in student's own attendance, never classmates'. → Task 1 test.
5. **Narrow screens**: at 360px the shell, stat cards and weekly columns don't overflow the page, and the drawer is reachable and closable with the keyboard. → Task 2 and Task 3 manual checks.

---

### Task 1: `StudentDashboard` data

**Files:**

- Create: `app/Services/StudentDashboard.php`
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/StudentDashboardTest.php`

**Interfaces:**

- Consumes: `User`, `ClassGroup::activeStudents`/`enrolledClassGroups`, `Lesson` (`localStart`, `localEnd`, `isCancelled`), `AttendanceRecord`, `AttendanceStatus`, `config('academy.timezone')`.
- Produces `StudentDashboard::for(User $student, bool $nextWeek = false): array` with:
    - `header: { yearGroup: string|null, weekLabel: string }`, where `weekLabel` looks like "Week of 13 – 19 October" or "Week of 27 October – 2 November";
    - `stats: { attendance: int|null, onTime: int|null, classTime: string, lessonsThisWeek: int }` (percentages are 0–100, or null with no records in the last 30 days);
    - `strands: { key, name, summary }[]`, the 4 strands in a fixed order;
    - `today: TodayLesson[]` and `todayMinutes: int`;
    - `week: Day[]` (only days that have lessons) and `nextWeek: bool`.
    - **`TodayLesson`** (also used inside `Day.lessons`): `{ id, className, time, location, minutes, state, attendance }`, where `state` is `'upcoming'|'in_progress'|'finished'|'cancelled'` and `attendance` is the student's own status value or null.
    - **`Day`:** `{ key, name, date, minutes, lessons: TodayLesson[] }`.
    - The controller reads `?week=next`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest StudentDashboardTest --no-interaction`:

```php
<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    // Wednesday 15 October 2025, 12:00 in Lagos.
    $this->travelTo(CarbonImmutable::parse('2025-10-15 11:00:00', 'UTC'));
    $this->ava = User::factory()->student(YearGroup::Year5)->create();
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths', 'location' => 'Room 1']);
    $this->group->enrol($this->ava);
    $this->lessonAt = fn (string $utcStart, int $minutes = 60, ?ClassGroup $group = null) => Lesson::factory()->for($group ?? $this->group)->create([
        'starts_at' => CarbonImmutable::parse($utcStart, 'UTC'),
        'ends_at' => CarbonImmutable::parse($utcStart, 'UTC')->addMinutes($minutes),
    ]);
});

it('shows a friendly empty dashboard for a student with nothing yet', function () {
    $this->actingAs(User::factory()->student(YearGroup::Year3)->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/student')
            ->where('header.yearGroup', 'Year 3')
            ->where('header.weekLabel', 'Week of 13 – 19 October')
            ->where('stats.attendance', null)
            ->where('stats.onTime', null)
            ->where('stats.classTime', '0m')
            ->where('stats.lessonsThisWeek', 0)
            ->has('strands', 4)
            ->where('strands.0.name', 'Number')
            ->where('strands.3.name', 'Geometry')
            ->has('today', 0)
            ->has('week', 0));
});

it('summarises today in academy time with the student\'s own attendance', function () {
    $finished = ($this->lessonAt)('2025-10-15 08:00:00', 45);  // 09:00–09:45 Lagos, already over
    $now = ($this->lessonAt)('2025-10-15 10:30:00', 90);       // 11:30–13:00, in progress
    $later = ($this->lessonAt)('2025-10-15 15:00:00', 60);     // 16:00–17:00, upcoming
    app(RecordAttendance::class)->handle($finished, $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-15 08:05:00', 'UTC'));

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('today', 3)
            ->where('today.0.className', 'Year 5 Maths')
            ->where('today.0.time', '09:00–09:45')
            ->where('today.0.location', 'Room 1')
            ->where('today.0.minutes', 45)
            ->where('today.0.state', 'finished')
            ->where('today.0.attendance', 'late')
            ->where('today.1.state', 'in_progress')
            ->where('today.2.state', 'upcoming')
            ->where('today.2.attendance', null)
            ->where('todayMinutes', 195));
});

it('works out attendance and punctuality over the last 30 days', function () {
    $record = app(RecordAttendance::class);
    $record->handle(($this->lessonAt)('2025-10-01 09:00:00'), $this->ava, AttendanceStatus::Present, null);
    $record->handle(($this->lessonAt)('2025-10-04 09:00:00'), $this->ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:10:00', 'UTC'));
    $record->handle(($this->lessonAt)('2025-10-08 09:00:00'), $this->ava, AttendanceStatus::Absent, null);
    $record->handle(($this->lessonAt)('2025-10-11 09:00:00'), $this->ava, AttendanceStatus::Excused, null);
    $record->handle(($this->lessonAt)('2025-08-01 09:00:00'), $this->ava, AttendanceStatus::Absent, null); // older than 30 days

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.attendance', 67) // 2 attended of 3 (excused not counted)
            ->where('stats.onTime', 50));   // 1 on time of 2 attended
});

it('lists the week Monday to Sunday and totals class time, skipping cancelled lessons', function () {
    ($this->lessonAt)('2025-10-13 09:00:00', 60);                          // Monday
    ($this->lessonAt)('2025-10-15 15:00:00', 39);                          // Wednesday
    tap(($this->lessonAt)('2025-10-17 09:00:00', 60), fn (Lesson $lesson) => $lesson->update(['status' => LessonStatus::Cancelled])); // Friday
    ($this->lessonAt)('2025-10-19 22:30:00', 60);                          // Monday 20th 23:30 Lagos? no: Sunday 19 23:30 Lagos
    ($this->lessonAt)('2025-10-19 23:30:00', 60);                          // Monday 20 Oct 00:30 Lagos → next week

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.lessonsThisWeek', 3)
            ->where('stats.classTime', '2h 39m')
            ->has('week', 4)
            ->where('week.0.name', 'Monday')
            ->where('week.0.date', '13 Oct')
            ->where('week.0.minutes', 60)
            ->where('week.2.name', 'Friday')
            ->where('week.2.lessons.0.state', 'cancelled')
            ->where('week.2.minutes', 0)
            ->where('week.3.name', 'Sunday'));

    $this->actingAs($this->ava)->get(route('dashboard', ['week' => 'next']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nextWeek', true)
            ->where('header.weekLabel', 'Week of 20 – 26 October')
            ->has('week', 1)
            ->where('week.0.name', 'Monday')
            ->where('week.0.lessons.0.time', '00:30–01:30'));
});

it('leaves out classes the student has left and never shows classmates\' records', function () {
    $left = ClassGroup::factory()->create(['name' => 'Old Class']);
    $left->enrol($this->ava);
    $left->withdraw($this->ava);
    ($this->lessonAt)('2025-10-15 15:00:00', 60, $left);

    $lesson = ($this->lessonAt)('2025-10-15 08:00:00', 45);
    $classmate = User::factory()->student()->create();
    $this->group->enrol($classmate);
    app(RecordAttendance::class)->handle($lesson, $classmate, AttendanceStatus::Absent, null);

    $this->actingAs($this->ava)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('today', 1)
            ->where('today.0.className', 'Year 5 Maths')
            ->where('today.0.attendance', null)
            ->where('stats.attendance', null));
});
```

In the week test, the `2025-10-19 22:30:00` UTC line is 23:30 Sunday in Lagos, which is still this week, so that row is the Sunday entry and `week.3`. The comment on it is muddled: replace it with `// Sunday 19 Oct 23:30 Lagos` when writing the file.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/StudentDashboardTest.php`
Expected: FAIL, because the `header` prop is missing.

- [ ] **Step 3: The service**

`php artisan make:class Services/StudentDashboard --no-interaction`, then:

```php
<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the student dashboard shows, built from the student's own
 * enrolments, lessons and attendance. Times are formatted in the academy timezone.
 */
class StudentDashboard
{
    /**
     * Maths strands shown on the dashboard. Quiz progress for each arrives in Phase 5.
     */
    private const STRANDS = [
        ['key' => 'number', 'name' => 'Number', 'summary' => 'Place value, the four operations, fractions, decimals and percentages.'],
        ['key' => 'algebra', 'name' => 'Algebra', 'summary' => 'Patterns, sequences, expressions and solving equations.'],
        ['key' => 'statistics', 'name' => 'Statistics', 'summary' => 'Tables, charts and graphs, averages and interpreting data.'],
        ['key' => 'geometry', 'name' => 'Geometry', 'summary' => 'Shapes, angles, position and direction, area and volume.'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(User $student, bool $nextWeek = false): array
    {
        $timezone = config('academy.timezone');
        $now = CarbonImmutable::now();
        $weekStart = $now->setTimezone($timezone)->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($nextWeek ? 1 : 0);
        $weekEnd = $weekStart->endOfWeek(CarbonImmutable::SUNDAY);
        $todayStart = $now->setTimezone($timezone)->startOfDay();

        $classIds = $student->enrolledClassGroups()->wherePivotNull('left_at')->pluck('class_groups.id');

        $lessonsBetween = fn (CarbonImmutable $from, CarbonImmutable $to): Collection => Lesson::query()
            ->whereIn('class_group_id', $classIds)
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])
            ->with(['classGroup', 'attendanceRecords' => fn ($query) => $query->where('user_id', $student->id)])
            ->orderBy('starts_at')
            ->get();

        $today = $lessonsBetween($todayStart, $todayStart->endOfDay())->map(fn (Lesson $lesson): array => $this->lessonCard($lesson, $now));
        $weekLessons = $lessonsBetween($weekStart, $weekEnd);
        $thisWeekLessons = $nextWeek ? $lessonsBetween($weekStart->subWeek(), $weekEnd->subWeek()) : $weekLessons;
        $countable = $thisWeekLessons->reject(fn (Lesson $lesson): bool => $lesson->isCancelled());

        return [
            'header' => [
                'yearGroup' => $student->studentProfile?->year_group->label(),
                'weekLabel' => $this->weekLabel($weekStart, $weekEnd),
            ],
            'stats' => [
                ...$this->attendanceStats($student, $now),
                'classTime' => $this->duration($countable->sum(fn (Lesson $lesson): int => $this->minutes($lesson))),
                'lessonsThisWeek' => $countable->count(),
            ],
            'strands' => self::STRANDS,
            'today' => $today->values()->all(),
            'todayMinutes' => $today->reject(fn (array $card): bool => $card['state'] === 'cancelled')->sum('minutes'),
            'week' => $weekLessons
                ->groupBy(fn (Lesson $lesson): string => $lesson->localStart()->toDateString())
                ->map(function (Collection $lessons) use ($now): array {
                    $day = $lessons->first()->localStart();
                    $cards = $lessons->map(fn (Lesson $lesson): array => $this->lessonCard($lesson, $now));

                    return [
                        'key' => $day->toDateString(),
                        'name' => $day->format('l'),
                        'date' => $day->format('j M'),
                        'minutes' => $cards->reject(fn (array $card): bool => $card['state'] === 'cancelled')->sum('minutes'),
                        'lessons' => $cards->values()->all(),
                    ];
                })
                ->values()
                ->all(),
            'nextWeek' => $nextWeek,
        ];
    }

    /**
     * Attendance = attended / (attended + absent); on time = present / attended. Excused is left out. Last 30 days.
     *
     * @return array{attendance: int|null, onTime: int|null}
     */
    private function attendanceStats(User $student, CarbonImmutable $now): array
    {
        $statuses = AttendanceRecord::query()
            ->where('user_id', $student->id)
            ->whereHas('lesson', fn ($query) => $query->whereBetween('starts_at', [$now->subDays(30), $now]))
            ->pluck('status');

        $present = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Present)->count();
        $late = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Late)->count();
        $absent = $statuses->filter(fn (AttendanceStatus $status): bool => $status === AttendanceStatus::Absent)->count();
        $attended = $present + $late;

        return [
            'attendance' => $attended + $absent === 0 ? null : (int) round($attended / ($attended + $absent) * 100),
            'onTime' => $attended === 0 ? null : (int) round($present / $attended * 100),
        ];
    }

    /**
     * @return array{id: int, className: string, time: string, location: string|null, minutes: int, state: string, attendance: string|null}
     */
    private function lessonCard(Lesson $lesson, CarbonImmutable $now): array
    {
        $state = match (true) {
            $lesson->isCancelled() => 'cancelled',
            $now->lessThan($lesson->starts_at) => 'upcoming',
            $now->lessThan($lesson->ends_at) => 'in_progress',
            default => 'finished',
        };

        return [
            'id' => $lesson->id,
            'className' => $lesson->classGroup->name,
            'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
            'location' => $lesson->classGroup->location,
            'minutes' => $this->minutes($lesson),
            'state' => $state,
            'attendance' => $lesson->attendanceRecords->first()?->status->value,
        ];
    }

    private function minutes(Lesson $lesson): int
    {
        return (int) $lesson->starts_at->diffInMinutes($lesson->ends_at);
    }

    private function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours === 0 ? "{$rest}m" : "{$hours}h {$rest}m";
    }

    private function weekLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->month === $end->month
            ? "Week of {$start->format('j')} – {$end->format('j F')}"
            : "Week of {$start->format('j F')} – {$end->format('j F')}";
    }
}
```

A few notes on the service:

- `diffInMinutes` returns a float in Carbon 3, hence the `(int)` cast.
- `whereHas('lesson', ...)` keeps the stats strictly to the last 30 days of lessons.
- `classGroup` on a lesson can't be null, because the FK cascades.

- [ ] **Step 4: Controller**

In `DashboardController::__invoke`, extend the `match` (add `use App\Services\StudentDashboard;`):

```php
Role::Student => app(StudentDashboard::class)->for($user, $request->query('week') === 'next'),
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/StudentDashboardTest.php tests/Feature/DashboardTest.php`
Expected: PASS (5 plus the existing DashboardTest). At this point the frontend still renders the old student page with extra props, which is fine.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/StudentDashboard.php app/Http/Controllers/DashboardController.php tests/Feature/StudentDashboardTest.php
git commit -m "feat: student dashboard data from real lessons and attendance"
```

---

### Task 2: Sidebar app shell (all roles)

**Files:**

- Create: `resources/js/components/icon.tsx`, `resources/js/components/app-sidebar.tsx`
- Modify: `resources/js/layouts/app-layout.tsx`, `resources/js/components/app-logo-image.tsx` (an `onDark` panel variant)
- Test: the existing PHP suite (every page still renders), `npm run types:check`, `npm run build`, plus a manual 360px check

**Interfaces:**

- Produces:
    - `Icon({ name, className? })`, where `name` is one of `'dashboard' | 'users' | 'classes' | 'children' | 'today' | 'logout' | 'menu' | 'close' | 'panel' | 'chart' | 'check' | 'clock' | 'trophy' | 'list' | 'columns' | 'book'`;
    - `AppLayout({ title, subtitle?, badge?, actions?, children })`, which keeps `title` and `children` exactly as today;
    - `AppLogoImage` gains `onDark?: boolean`, which always puts the logo on a light panel.

- [ ] **Step 1: Icons**

`resources/js/components/icon.tsx`: 24×24 stroke icons (`fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"`, `aria-hidden="true"`, default class `size-5`):

```tsx
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

const paths: Record<string, ReactNode> = {
    dashboard: (
        <>
            <rect x="3" y="3" width="7" height="7" rx="1.5" />
            <rect x="14" y="3" width="7" height="7" rx="1.5" />
            <rect x="3" y="14" width="7" height="7" rx="1.5" />
            <rect x="14" y="14" width="7" height="7" rx="1.5" />
        </>
    ),
    users: (
        <>
            <circle cx="9" cy="8" r="3.5" />
            <path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
            <path d="M16 4.5a3.5 3.5 0 0 1 0 7M18.5 20a6.5 6.5 0 0 0-2.5-5.1" />
        </>
    ),
    classes: (
        <>
            <rect x="3" y="4.5" width="18" height="16" rx="2" />
            <path d="M3 9.5h18M8 2.5v4M16 2.5v4" />
        </>
    ),
    children: (
        <>
            <circle cx="8" cy="7" r="3" />
            <circle cx="17" cy="9" r="2.5" />
            <path d="M2.5 20a5.5 5.5 0 0 1 11 0M13.5 20a4 4 0 0 1 8 0" />
        </>
    ),
    today: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    clock: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    logout: (
        <>
            <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3" />
            <path d="M10 16l-4-4 4-4M6 12h10" />
        </>
    ),
    menu: <path d="M4 6h16M4 12h16M4 18h16" />,
    close: <path d="M6 6l12 12M18 6L6 18" />,
    panel: (
        <>
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="M9 4v16" />
        </>
    ),
    chart: <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />,
    check: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M8 12.5l2.5 2.5L16 9.5" />
        </>
    ),
    trophy: (
        <>
            <path d="M8 4h8v5a4 4 0 0 1-8 0V4Z" />
            <path d="M8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8.5 20h7M10 17h4" />
        </>
    ),
    list: <path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01" />,
    columns: (
        <>
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="M9 4v16M15 4v16" />
        </>
    ),
    book: (
        <>
            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15Z" />
            <path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5" />
        </>
    ),
};

export type IconName = keyof typeof paths;

export default function Icon({
    name,
    className,
}: {
    name: IconName;
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={cn('size-5 shrink-0', className)}
        >
            {paths[name]}
        </svg>
    );
}
```

`keyof typeof paths` on a `Record<string, …>` gives `string`. Declare `paths` with `satisfies Record<…, ReactNode>`, or type `IconName` as the explicit union from the Interfaces block, so `tsc` catches typos. Use the explicit union.

- [ ] **Step 2: Logo on a dark background**

In `app-logo-image.tsx`, add the prop `onDark?: boolean`. When it's true, the wrapper is always `rounded-lg bg-white px-3 py-2`, and the `<img>` has no `mix-blend-multiply` (the PNG is transparent). Keep the existing behaviour when it's false.

- [ ] **Step 3: Sidebar**

`resources/js/components/app-sidebar.tsx`:

```tsx
import { Link, usePage } from '@inertiajs/react';
import AppLogoImage from '@/components/app-logo-image';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { cn } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { index as adminClassesIndex } from '@/routes/admin/classes';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { index as childrenIndex } from '@/routes/children';
import type { Role } from '@/types';

type NavItem = {
    label: string;
    icon: IconName;
    href: () => { url: string; method: 'get' };
};

const navItems: Record<Role, NavItem[]> = {
    admin: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
        { label: 'Users', icon: 'users', href: adminUsersIndex },
        { label: 'Classes', icon: 'classes', href: adminClassesIndex },
    ],
    tutor: [{ label: 'Today', icon: 'today', href: dashboard }],
    student: [{ label: 'Dashboard', icon: 'dashboard', href: dashboard }],
    guardian: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
        { label: 'Children', icon: 'children', href: childrenIndex },
    ],
};

type AppSidebarProps = { collapsed: boolean; onNavigate?: () => void };

export default function AppSidebar({ collapsed, onNavigate }: AppSidebarProps) {
    const { auth } = usePage().props;
    const currentUrl = usePage().url;

    if (!auth.user) {
        return null;
    }

    const isActive = (url: string) =>
        currentUrl === url ||
        currentUrl.startsWith(`${url}/`) ||
        currentUrl.startsWith(`${url}?`);

    return (
        <div className="flex h-full flex-col gap-6 bg-royal-950 px-3 py-5 text-royal-100">
            <Link
                href={dashboard()}
                onClick={onNavigate}
                aria-label="Jerry Great Academy dashboard"
                className={cn('px-1', collapsed && 'mx-auto')}
            >
                <AppLogoImage onDark className={collapsed ? 'w-12' : 'w-40'} />
            </Link>
            <nav aria-label="Main" className="flex flex-1 flex-col gap-1">
                {navItems[auth.user.role].map((item) => {
                    const url = item.href().url;
                    const active = isActive(url);

                    return (
                        <Link
                            key={item.label}
                            href={item.href()}
                            onClick={onNavigate}
                            aria-current={active ? 'page' : undefined}
                            title={collapsed ? item.label : undefined}
                            className={cn(
                                'flex h-11 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors',
                                active
                                    ? 'bg-royal-700 text-white shadow-raised'
                                    : 'text-royal-200 hover:bg-royal-900 hover:text-white',
                                collapsed && 'justify-center px-0',
                            )}
                        >
                            <Icon name={item.icon} />
                            <span className={cn(collapsed && 'sr-only')}>
                                {item.label}
                            </span>
                        </Link>
                    );
                })}
            </nav>
            <Link
                href={logout()}
                as="button"
                title={collapsed ? 'Log out' : undefined}
                className={cn(
                    'flex h-11 items-center gap-3 rounded-md px-3 text-sm font-medium text-royal-200 hover:bg-royal-900 hover:text-white',
                    collapsed && 'justify-center px-0',
                )}
            >
                <Icon name="logout" />
                <span className={cn(collapsed && 'sr-only')}>Log out</span>
            </Link>
        </div>
    );
}
```

`isActive` compares against the URL path. Wayfinder `.url` returns a path such as `/admin/users`, and `usePage().url` is path plus query, so `Dashboard` (`/dashboard`) stays active for `/dashboard?week=next`. If Wayfinder returns absolute URLs here, compare `new URL(url, location.origin).pathname` and ledger it.

- [ ] **Step 4: Layout**

Replace `resources/js/layouts/app-layout.tsx`:

```tsx
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import AppSidebar from '@/components/app-sidebar';
import Icon from '@/components/icon';
import Toast from '@/components/toast';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { Role } from '@/types';

const roleLabels: Record<Role, string> = {
    admin: 'Admin',
    tutor: 'Tutor',
    student: 'Student',
    guardian: 'Parent',
};
const COLLAPSE_KEY = 'jga.sidebar.collapsed';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
        return false;
    }
}

type AppLayoutProps = {
    title: string;
    subtitle?: string;
    badge?: string;
    actions?: ReactNode;
    children: ReactNode;
};

export default function AppLayout({
    title,
    subtitle,
    badge,
    actions,
    children,
}: AppLayoutProps) {
    const { auth } = usePage().props;
    const [collapsed, setCollapsed] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);

    useEffect(() => setCollapsed(readCollapsed()), []);

    useEffect(() => {
        if (!drawerOpen) {
            return;
        }
        const onKey = (event: KeyboardEvent) =>
            event.key === 'Escape' && setDrawerOpen(false);
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [drawerOpen]);

    const toggleCollapsed = () => {
        const next = !collapsed;
        setCollapsed(next);
        try {
            window.localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
        } catch {
            // Storage can be unavailable (private mode); the toggle still works for this visit.
        }
    };

    return (
        <div className="min-h-screen bg-background lg:flex">
            <Head title={title} />
            <aside
                className={cn(
                    'sticky top-0 hidden h-screen shrink-0 transition-[width] lg:block',
                    collapsed ? 'w-20' : 'w-64',
                )}
            >
                <AppSidebar collapsed={collapsed} />
            </aside>

            <div className="flex items-center justify-between gap-3 bg-royal-950 px-4 py-3 lg:hidden">
                <span className="font-display text-lg font-bold tracking-wide text-white uppercase">
                    Jerry Great Academy
                </span>
                <button
                    type="button"
                    onClick={() => setDrawerOpen(true)}
                    aria-label="Open menu"
                    aria-expanded={drawerOpen}
                    className="rounded-md p-2 text-royal-100 hover:bg-royal-900"
                >
                    <Icon name="menu" />
                </button>
            </div>
            {drawerOpen && (
                <div
                    className="fixed inset-0 z-40 lg:hidden"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Menu"
                >
                    <button
                        type="button"
                        aria-label="Close menu"
                        className="absolute inset-0 bg-royal-950/60"
                        onClick={() => setDrawerOpen(false)}
                    />
                    <div className="relative h-full w-72 max-w-[85vw]">
                        <AppSidebar
                            collapsed={false}
                            onNavigate={() => setDrawerOpen(false)}
                        />
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(false)}
                            aria-label="Close menu"
                            autoFocus
                            className="absolute top-4 right-3 rounded-md p-2 text-royal-100 hover:bg-royal-900"
                        >
                            <Icon name="close" />
                        </button>
                    </div>
                </div>
            )}

            <div className="min-w-0 flex-1">
                <header className="mx-auto flex max-w-7xl flex-wrap items-start justify-between gap-4 px-4 pt-6 sm:px-6 lg:px-8 lg:pt-8">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={toggleCollapsed}
                            aria-label={
                                collapsed
                                    ? 'Expand sidebar'
                                    : 'Collapse sidebar'
                            }
                            aria-pressed={collapsed}
                            className="hidden rounded-md p-2 text-muted-foreground hover:bg-surface-muted lg:inline-flex"
                        >
                            <Icon name="panel" />
                        </button>
                        <h1 className="font-display text-3xl font-bold tracking-wide uppercase sm:text-4xl">
                            {title}
                        </h1>
                    </div>
                    {auth.user && (
                        <div className="text-right">
                            <p className="flex items-center justify-end gap-2 font-semibold">
                                {auth.user.name}
                                <Badge tone="brand">
                                    {badge ?? roleLabels[auth.user.role]}
                                </Badge>
                            </p>
                            {subtitle && (
                                <p className="text-sm text-muted-foreground">
                                    {subtitle}
                                </p>
                            )}
                        </div>
                    )}
                </header>
                <main className="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8">
                    <Toast />
                    {actions && (
                        <div className="flex flex-wrap gap-3">{actions}</div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
```

Remove the old top-bar nav and its `navItems` from this file; they now live in `app-sidebar.tsx`. Pages that pass only `title` and `children` keep working unchanged.

- [ ] **Step 5: Verify**

```bash
php artisan wayfinder:generate --with-form
npm run check:fix && npm run types:check && npm run build
php artisan test --compact
```

Expected: all green. Then do a manual check over Herd at 1280px and at 360px, using the browser's responsive mode, for each role:

- the sidebar is active on the current page;
- the drawer opens, Escape closes it, and clicking a link closes it;
- the collapse toggle persists across reloads.

Ledger the result.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/icon.tsx resources/js/components/app-sidebar.tsx resources/js/components/app-logo-image.tsx resources/js/layouts/app-layout.tsx
git commit -m "feat: sidebar app shell for every role"
```

---

### Task 3: Student dashboard page

**Files:**

- Create: `resources/js/components/stat-card.tsx`, `resources/js/components/strand-card.tsx`, `resources/js/components/lesson-activity-card.tsx`, `resources/js/components/weekly-activity.tsx`
- Modify: `resources/js/pages/dashboard/student.tsx`, `resources/js/types/ui.ts` (dashboard types)
- Test: `tests/Feature/StudentDashboardTest.php` (still green), types and build, plus a manual check

**Interfaces:**

- Consumes: the Task 1 props and the Task 2 `AppLayout({ title, subtitle, badge })` and `Icon`.
- Produces: the TS types `DashboardLesson`, `DashboardDay`, `Strand`, `StudentStats` (in `ui.ts`), and the components below.

- [ ] **Step 1: Types**

Append to `resources/js/types/ui.ts`:

```ts
export type LessonState = 'upcoming' | 'in_progress' | 'finished' | 'cancelled';

export type DashboardLesson = {
    id: number;
    className: string;
    time: string;
    location: string | null;
    minutes: number;
    state: LessonState;
    attendance: 'present' | 'late' | 'absent' | 'excused' | null;
};

export type DashboardDay = {
    key: string;
    name: string;
    date: string;
    minutes: number;
    lessons: DashboardLesson[];
};

export type Strand = {
    key: 'number' | 'algebra' | 'statistics' | 'geometry';
    name: string;
    summary: string;
};

export type StudentStats = {
    attendance: number | null;
    onTime: number | null;
    classTime: string;
    lessonsThisWeek: number;
};
```

- [ ] **Step 2: Components**

`stat-card.tsx`: a big `font-display` value, a label, a hint line, and an icon tile on the right (`size-16 rounded-xl` with a tone background):

```tsx
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

const tones = {
    royal: 'bg-royal-100 text-royal-700 dark:bg-royal-800/50 dark:text-royal-200',
    gold: 'bg-gold-100 text-gold-700 dark:bg-gold-500/15 dark:text-gold-300',
    present: 'bg-present-soft text-present',
} as const;

type StatCardProps = {
    value: string;
    label: string;
    hint?: string;
    icon: IconName;
    tone: keyof typeof tones;
};

export default function StatCard({
    value,
    label,
    hint,
    icon,
    tone,
}: StatCardProps) {
    return (
        <Card className="flex items-center justify-between gap-4 p-6">
            <div className="min-w-0">
                <p className="font-display text-4xl leading-none font-bold tabular-nums">
                    {value}
                </p>
                <p className="mt-2 text-base text-muted-foreground">{label}</p>
                {hint && (
                    <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
                )}
            </div>
            <div
                className={cn(
                    'flex size-16 shrink-0 items-center justify-center rounded-xl',
                    tones[tone],
                )}
            >
                <Icon name={icon} className="size-8" />
            </div>
        </Card>
    );
}
```

`strand-card.tsx`: a header (strand name plus a year badge), the summary, a progress track showing "Quizzes coming soon" with 0 progress, and a trophy chip. It's colour-coded with the `strand-*` token through a left accent bar:

```tsx
import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { Strand } from '@/types';

const accents: Record<Strand['key'], string> = {
    number: 'bg-strand-number',
    algebra: 'bg-strand-algebra',
    statistics: 'bg-strand-statistics',
    geometry: 'bg-strand-geometry',
};

export default function StrandCard({
    strand,
    yearGroup,
}: {
    strand: Strand;
    yearGroup: string | null;
}) {
    return (
        <Card className="flex flex-col">
            <div className="flex items-start justify-between gap-3 border-b px-6 py-5">
                <div>
                    <h3 className="flex items-center gap-2 text-xl font-semibold">
                        <span
                            className={cn(
                                'size-2.5 rounded-full',
                                accents[strand.key],
                            )}
                            aria-hidden="true"
                        />
                        {strand.name}
                        {yearGroup && <Badge tone="brand">{yearGroup}</Badge>}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">Maths</p>
                </div>
            </div>
            <div className="flex flex-1 flex-col gap-4 px-6 py-5">
                <p className="text-sm leading-relaxed">{strand.summary}</p>
                <div className="mt-auto flex items-center gap-3">
                    <div
                        className="relative h-6 flex-1 overflow-hidden rounded-full bg-surface-muted"
                        role="img"
                        aria-label={`${strand.name} quizzes are coming soon`}
                    >
                        <span className="absolute inset-0 flex items-center justify-center text-xs font-medium text-muted-foreground">
                            Quizzes coming soon
                        </span>
                    </div>
                    <span
                        className="flex size-10 items-center justify-center rounded-full bg-gold-100 text-gold-700 shadow-card dark:bg-gold-500/15 dark:text-gold-300"
                        aria-hidden="true"
                    >
                        <Icon name="trophy" />
                    </span>
                </div>
            </div>
        </Card>
    );
}
```

`lesson-activity-card.tsx`: the class name plus a state badge, an inner tile (time range, location, book icon), a "Time: N minutes" line with the clock icon, and an attendance line (the badge if recorded, otherwise "Not recorded yet"):

```tsx
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import type { BadgeTone } from '@/components/ui/badge';
import type { DashboardLesson, LessonState } from '@/types';

const states: Record<LessonState, { label: string; tone: BadgeTone }> = {
    upcoming: { label: 'Not started', tone: 'neutral' },
    in_progress: { label: 'In progress', tone: 'gold' },
    finished: { label: 'Finished', tone: 'brand' },
    cancelled: { label: 'Cancelled', tone: 'absent' },
};

export default function LessonActivityCard({
    lesson,
}: {
    lesson: DashboardLesson;
}) {
    const state = states[lesson.state];

    return (
        <article className="flex min-w-60 flex-col gap-3 rounded-lg border bg-surface-muted/60 p-4">
            <div className="flex items-start justify-between gap-2">
                <h3 className="font-semibold leading-tight">
                    {lesson.className}
                </h3>
                <Badge tone={state.tone}>{state.label}</Badge>
            </div>
            <div className="flex items-center justify-between rounded-md bg-surface p-3 shadow-card">
                <div>
                    <p className="font-display text-xl font-bold tabular-nums">
                        {lesson.time}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {lesson.location ?? 'Location to be confirmed'}
                    </p>
                </div>
                <Icon name="book" className="size-7 text-royal-400" />
            </div>
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Icon name="clock" className="size-4" /> Time: {lesson.minutes}{' '}
                minutes
            </p>
            <div className="flex min-h-6 items-center">
                {lesson.attendance ? (
                    <AttendanceStatusBadge status={lesson.attendance} />
                ) : (
                    <span className="text-xs text-muted-foreground">
                        {lesson.state === 'cancelled'
                            ? 'No class today'
                            : 'Attendance not recorded yet'}
                    </span>
                )}
            </div>
        </article>
    );
}
```

`weekly-activity.tsx`: a section with the heading "Weekly activity" and a toolbar holding:

- a week switch: two `Link`s to `dashboard()` and `dashboard({ query: { week: 'next' } })`, styled as a segmented control, with `aria-current` on the active one;
- a Kanban/List toggle: `aria-pressed` buttons, with local state.

Kanban mode is a horizontal `overflow-x-auto` row of day columns (`w-72 shrink-0`), each with a day header (name, date, "Total time: N min") and stacked `LessonActivityCard`s. List mode is a simple table of day, class, time, minutes and state. With no days, it shows an empty state: "No lessons this week" or "No lessons next week".

```tsx
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/icon';
import LessonActivityCard from '@/components/lesson-activity-card';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { DashboardDay } from '@/types';

export default function WeeklyActivity({
    week,
    nextWeek,
}: {
    week: DashboardDay[];
    nextWeek: boolean;
}) {
    const [view, setView] = useState<'kanban' | 'list'>('kanban');
    const segment =
        'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-medium';

    return (
        <section
            className="flex flex-col gap-4"
            aria-labelledby="weekly-activity"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2
                    id="weekly-activity"
                    className="font-display text-2xl font-bold tracking-wide uppercase"
                >
                    Weekly activity
                </h2>
                <div className="flex flex-wrap items-center gap-3">
                    <div className="inline-flex rounded-lg border bg-surface p-1">
                        <Link
                            href={dashboard()}
                            aria-current={!nextWeek ? 'page' : undefined}
                            className={cn(
                                segment,
                                !nextWeek
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            This week
                        </Link>
                        <Link
                            href={dashboard({ query: { week: 'next' } })}
                            aria-current={nextWeek ? 'page' : undefined}
                            className={cn(
                                segment,
                                nextWeek
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            Next week
                        </Link>
                    </div>
                    <div
                        className="inline-flex rounded-lg border bg-surface p-1"
                        role="group"
                        aria-label="View"
                    >
                        <button
                            type="button"
                            aria-pressed={view === 'kanban'}
                            onClick={() => setView('kanban')}
                            className={cn(
                                segment,
                                view === 'kanban'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <Icon name="columns" className="size-4" /> Kanban
                        </button>
                        <button
                            type="button"
                            aria-pressed={view === 'list'}
                            onClick={() => setView('list')}
                            className={cn(
                                segment,
                                view === 'list'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <Icon name="list" className="size-4" /> List
                        </button>
                    </div>
                </div>
            </div>
            {week.length === 0 ? (
                <Card className="p-8 text-center text-muted-foreground">
                    No lessons {nextWeek ? 'next' : 'this'} week.
                </Card>
            ) : view === 'kanban' ? (
                <div className="flex gap-4 overflow-x-auto pb-2">
                    {week.map((day) => (
                        <Card
                            key={day.key}
                            className="flex w-72 shrink-0 flex-col gap-3 p-4"
                        >
                            <div>
                                <h3 className="text-lg font-semibold">
                                    {day.name}{' '}
                                    <span className="text-sm font-normal text-muted-foreground">
                                        {day.date}
                                    </span>
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Total time: {day.minutes} min
                                </p>
                            </div>
                            {day.lessons.map((lesson) => (
                                <LessonActivityCard
                                    key={lesson.id}
                                    lesson={lesson}
                                />
                            ))}
                        </Card>
                    ))}
                </div>
            ) : (
                <Card className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b bg-surface-muted text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Day
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Class
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Time
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Minutes
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {week.flatMap((day) =>
                                day.lessons.map((lesson) => (
                                    <tr
                                        key={lesson.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3">
                                            {day.name} {day.date}
                                        </td>
                                        <td className="px-4 py-3 font-medium">
                                            {lesson.className}
                                            {lesson.state === 'cancelled' && (
                                                <span className="ml-2 text-xs text-absent">
                                                    Cancelled
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {lesson.time}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {lesson.minutes}
                                        </td>
                                    </tr>
                                )),
                            )}
                        </tbody>
                    </table>
                </Card>
            )}
        </section>
    );
}
```

Check that `dashboard({ query: { week: 'next' } })` matches the generated Wayfinder signature, which takes `RouteQueryOptions` as `options`, so it may need to be `dashboard({ query: { week: 'next' } })` or `dashboard({ mergeQuery: … })`. Ledger the form you use.

- [ ] **Step 3: The page**

Replace `resources/js/pages/dashboard/student.tsx`:

```tsx
import Icon from '@/components/icon';
import LessonActivityCard from '@/components/lesson-activity-card';
import StatCard from '@/components/stat-card';
import StrandCard from '@/components/strand-card';
import { Card } from '@/components/ui/card';
import WeeklyActivity from '@/components/weekly-activity';
import AppLayout from '@/layouts/app-layout';
import type {
    DashboardDay,
    DashboardLesson,
    Strand,
    StudentStats,
} from '@/types';

type StudentDashboardProps = {
    header: { yearGroup: string | null; weekLabel: string };
    stats: StudentStats;
    strands: Strand[];
    today: DashboardLesson[];
    todayMinutes: number;
    week: DashboardDay[];
    nextWeek: boolean;
};

const percent = (value: number | null) => (value === null ? '—' : `${value}%`);

export default function StudentDashboard({
    header,
    stats,
    strands,
    today,
    todayMinutes,
    week,
    nextWeek,
}: StudentDashboardProps) {
    return (
        <AppLayout
            title="Dashboard"
            badge={header.yearGroup ?? undefined}
            subtitle={header.weekLabel}
        >
            <section
                className="grid gap-4 md:grid-cols-3"
                aria-label="This week at a glance"
            >
                <StatCard
                    value={percent(stats.attendance)}
                    label="Attendance"
                    hint={
                        stats.attendance === null
                            ? 'Shows once your classes are marked'
                            : 'Last 30 days'
                    }
                    icon="chart"
                    tone="royal"
                />
                <StatCard
                    value={percent(stats.onTime)}
                    label="On time"
                    hint={
                        stats.onTime === null
                            ? 'Shows once you’ve attended a class'
                            : 'Of the classes you attended'
                    }
                    icon="check"
                    tone="present"
                />
                <StatCard
                    value={stats.classTime}
                    label="Class time this week"
                    hint={`${stats.lessonsThisWeek} ${stats.lessonsThisWeek === 1 ? 'lesson' : 'lessons'}`}
                    icon="clock"
                    tone="gold"
                />
            </section>

            <section
                className="grid gap-4 lg:grid-cols-2"
                aria-label="Maths topics"
            >
                {strands.map((strand) => (
                    <StrandCard
                        key={strand.key}
                        strand={strand}
                        yearGroup={header.yearGroup}
                    />
                ))}
            </section>

            <section
                className="flex flex-col gap-4"
                aria-labelledby="today-activity"
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2
                        id="today-activity"
                        className="font-display text-2xl font-bold tracking-wide uppercase"
                    >
                        Today’s activity
                    </h2>
                    <span className="inline-flex h-9 items-center gap-2 rounded-full border bg-surface px-4 text-sm">
                        <Icon name="clock" className="size-4" /> Total time:{' '}
                        {todayMinutes} min
                    </span>
                </div>
                {today.length === 0 ? (
                    <Card className="p-8 text-center text-muted-foreground">
                        No lessons today. Enjoy your day!
                    </Card>
                ) : (
                    <Card className="flex gap-4 overflow-x-auto p-4">
                        {today.map((lesson) => (
                            <LessonActivityCard
                                key={lesson.id}
                                lesson={lesson}
                            />
                        ))}
                    </Card>
                )}
            </section>

            <WeeklyActivity week={week} nextWeek={nextWeek} />
        </AppLayout>
    );
}
```

- [ ] **Step 4: Verify**

```bash
npm run check:fix && npm run types:check && npm run build
php artisan test --compact
php artisan migrate:fresh --seed --no-interaction
```

Expected: all green. Then do a manual check over Herd as `ava.year5`/`password`, at 1280px and 360px:

- three stat cards, four strand cards, Today's activity (or its empty state), and Weekly activity, where the seeded Saturday lesson appears;
- Next week switches the label;
- Kanban and List toggle.

Then check the tutor, parent and admin dashboards in the new shell. Ledger it.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/stat-card.tsx resources/js/components/strand-card.tsx resources/js/components/lesson-activity-card.tsx resources/js/components/weekly-activity.tsx resources/js/pages/dashboard/student.tsx resources/js/types/ui.ts
git commit -m "feat: student dashboard in the new design"
```
