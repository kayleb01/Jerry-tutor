<?php

namespace Database\Seeders;

use App\Actions\CreateStudentAccount;
use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\Weekday;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use App\Services\LessonScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic demo data so every admin screen has something to show: tutors, families,
 * students in every year group, classes, six weeks of registers (with genuine lateness
 * reports from RecordAttendance), end-of-class reports, follow-ups and today's lessons
 * in every state. Additive and safe to run again: `php artisan db:seed --class=DemoDataSeeder`.
 * Every account's password is "password".
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    private const MARKER_EMAIL = 'tutor.adaeze@academy.test';

    /**
     * @var array<int, array{name: string, email: string}>
     */
    private const TUTORS = [
        ['name' => 'Adaeze Okonkwo', 'email' => 'tutor.adaeze@academy.test'],
        ['name' => 'Tunde Bakare', 'email' => 'tutor.tunde@academy.test'],
        ['name' => 'Chioma Eze', 'email' => 'tutor.chioma@academy.test'],
        ['name' => 'Musa Ibrahim', 'email' => 'tutor.musa@academy.test'],
    ];

    /**
     * Classes: name, tutor index, year group (null = mixed), weekday, start time, minutes, years taught, location.
     *
     * @var array<int, array{name: string, tutor: int, year: int|null, weekday: Weekday, start: string, minutes: int, years: array<int, int>, location: string}>
     */
    private const CLASSES = [
        ['name' => 'Year 3 & 4 Maths', 'tutor' => 0, 'year' => 4, 'weekday' => Weekday::Monday, 'start' => '16:00', 'minutes' => 60, 'years' => [3, 4], 'location' => 'Room 1'],
        ['name' => 'Year 5 Maths', 'tutor' => 1, 'year' => 5, 'weekday' => Weekday::Tuesday, 'start' => '16:30', 'minutes' => 60, 'years' => [5], 'location' => 'Room 2'],
        ['name' => 'Year 6 SATs Prep', 'tutor' => 0, 'year' => 6, 'weekday' => Weekday::Thursday, 'start' => '17:00', 'minutes' => 75, 'years' => [6], 'location' => 'Room 1'],
        ['name' => '11+ Preparation', 'tutor' => 2, 'year' => null, 'weekday' => Weekday::Saturday, 'start' => '09:00', 'minutes' => 90, 'years' => [5, 6], 'location' => 'Hall'],
        ['name' => 'Year 7 Maths', 'tutor' => 2, 'year' => 7, 'weekday' => Weekday::Wednesday, 'start' => '15:30', 'minutes' => 60, 'years' => [7], 'location' => 'Room 3'],
        ['name' => 'Year 8 & 9 Maths', 'tutor' => 3, 'year' => 8, 'weekday' => Weekday::Friday, 'start' => '16:00', 'minutes' => 60, 'years' => [8, 9], 'location' => 'Room 3'],
        ['name' => 'GCSE Maths', 'tutor' => 3, 'year' => 10, 'weekday' => Weekday::Saturday, 'start' => '11:00', 'minutes' => 90, 'years' => [10, 11], 'location' => 'Online'],
    ];

    /**
     * Students per year group.
     *
     * @var array<int, array<int, string>>
     */
    private const STUDENTS = [
        3 => ['Amara Obi', 'Kemi Adeyemi', 'Tobi Okafor'],
        4 => ['Chidi Nwosu', 'Zainab Bello', 'David Etim'],
        5 => ['Ifeoma Uche', 'Samuel Ade', 'Halima Musa'],
        6 => ['Emeka Obi', 'Grace Akpan', 'Yusuf Lawal'],
        7 => ['Ngozi Okafor', 'Daniel Bakare', 'Aisha Ibrahim'],
        8 => ['Oluwaseun Adeyemi', 'Blessing Etim', 'Ibrahim Bello'],
        9 => ['Chinedu Nwosu', 'Fatima Lawal', 'Peter Akpan'],
        10 => ['Funmilayo Ade', 'Kelechi Uche', 'Maryam Musa'],
        11 => ['Uchenna Eze', 'Bisola Okonkwo', 'Joseph Akpan'],
    ];

    private const RELIABLY_LATE_STUDENT = 'Emeka Obi';

    private const RELIABLY_LATE_TUTOR = 3;

    private const WEEKS_OF_HISTORY = 6;

    /**
     * @var array<int, string>
     */
    private const TUTOR_WENT_WELL = [
        'Most of the class grasped the method quickly and helped each other.',
        'Good energy today; the starter quiz worked well.',
        'Strong progress on written methods.',
        'Students asked thoughtful questions about the worked examples.',
    ];

    /**
     * @var array<int, string>
     */
    private const TUTOR_RECOMMENDATIONS = [
        'Revisit times tables at the start of next lesson.',
        'Set extra practice on word problems for homework.',
        'Pair stronger students with those who found it hard.',
        'Spend more time on checking answers.',
    ];

    /**
     * @var array<int, string>
     */
    private const STUDENT_WENT_WELL = [
        'I understood fractions much better today.',
        'The games at the start were fun.',
        'I liked working in pairs.',
        'The examples on the board helped a lot.',
    ];

    /**
     * @var array<int, string>
     */
    private const STUDENT_ISSUES = [
        'The last question was confusing.',
        'It was a bit noisy at times.',
        'I need more time on long division.',
    ];

    public function run(): void
    {
        if (User::where('email', self::MARKER_EMAIL)->exists()) {
            $this->command?->info('Demo data is already present — nothing to do.');

            return;
        }

        mt_srand(2026);

        $admin = $this->admin();
        $tutors = array_map(fn (array $tutor): User => $this->user('tutor', $tutor['name'], $tutor['email']), self::TUTORS);
        $students = $this->students();
        $this->families($students);
        $classes = $this->classes($tutors, $students);

        $now = CarbonImmutable::now();
        foreach ($classes as $group) {
            $this->history($group, $now);
        }

        app(LessonScheduler::class)->generate();
        $this->today($classes, $now);
        $this->followUps($admin, $now);

        $this->command?->info('Demo data created: '.count($tutors).' tutors, '.count($students).' students, '.count($classes).' classes. Password for every account: "password".');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@academy.test')->first()
            ?? $this->user('admin', 'Academy Admin', 'admin@academy.test');
    }

    private function user(string $role, string $name, string $email): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * @return array<int, User>
     */
    private function students(): array
    {
        $create = app(CreateStudentAccount::class);
        $students = [];

        foreach (self::STUDENTS as $year => $names) {
            foreach ($names as $name) {
                $username = strtolower(strtok($name, ' ')).'.y'.$year;
                $students[] = User::where('username', $username)->first() ?? $create->handle([
                    'name' => $name,
                    'username' => $username,
                    'password' => 'password',
                    'year_group' => $year,
                ]);
            }
        }

        return $students;
    }

    /**
     * One parent per surname, linked to every child sharing it.
     *
     * @param  array<int, User>  $students
     */
    private function families(array $students): void
    {
        $bySurname = collect($students)->groupBy(fn (User $student): string => explode(' ', $student->name)[1]);

        foreach ($bySurname as $surname => $children) {
            $email = 'parent.'.strtolower($surname).'@academy.test';
            $parent = User::where('email', $email)->first() ?? $this->user('guardian', 'Mrs '.$surname, $email);

            foreach ($children as $child) {
                $parent->linkChild($child, 'Parent');
            }
        }
    }

    /**
     * @param  array<int, User>  $tutors
     * @param  array<int, User>  $students
     * @return array<int, ClassGroup>
     */
    private function classes(array $tutors, array $students): array
    {
        return array_map(function (array $definition) use ($tutors, $students): ClassGroup {
            $group = ClassGroup::create([
                'name' => $definition['name'],
                'tutor_id' => $tutors[$definition['tutor']]->id,
                'year_group' => $definition['year'] === null ? null : YearGroup::from($definition['year']),
                'location' => $definition['location'],
                'weekday' => $definition['weekday'],
                'start_time' => $definition['start'],
                'duration_minutes' => $definition['minutes'],
                'is_active' => true,
            ]);

            foreach ($students as $student) {
                $inYears = in_array($student->studentProfile->year_group->value, $definition['years'], true);
                // The 11+ class takes some, not all, Year 5s and 6s.
                if ($inYears && ($definition['year'] !== null || mt_rand(1, 100) <= 60)) {
                    $group->enrol($student);
                }
            }

            return $group;
        }, self::CLASSES);
    }

    /**
     * Past weekly lessons for the class, each with a full register and some reports.
     */
    private function history(ClassGroup $group, CarbonImmutable $now): void
    {
        $timezone = config('academy.timezone');
        [$hour, $minute] = array_map('intval', explode(':', $group->start_time));
        $thisWeek = $now->setTimezone($timezone)->startOfWeek(CarbonImmutable::MONDAY);

        for ($weeksAgo = self::WEEKS_OF_HISTORY; $weeksAgo >= 0; $weeksAgo--) {
            $startsAt = $thisWeek->subWeeks($weeksAgo)->addDays($group->weekday->value - 1)->setTime($hour, $minute)->utc();
            $endsAt = $startsAt->addMinutes($group->duration_minutes);

            if ($endsAt->greaterThan($now) || $startsAt->isSameDay($now->setTimezone($timezone))) {
                continue;
            }

            $lesson = Lesson::firstOrCreate(
                ['class_group_id' => $group->id, 'starts_at' => $startsAt],
                ['tutor_id' => $group->tutor_id, 'ends_at' => $endsAt, 'status' => LessonStatus::Completed],
            );

            $this->register($lesson, $group, full: true, tutorLateChance: $group->tutor_id === $this->reliablyLateTutorId() ? 65 : 8);
            $this->reports($lesson, $now);
        }
    }

    /**
     * Today's lessons in every state the admin dashboard shows.
     *
     * @param  array<int, ClassGroup>  $classes
     */
    private function today(array $classes, CarbonImmutable $now): void
    {
        $local = $now->setTimezone(config('academy.timezone'));
        $base = $local->setTime(max(10, min(19, $local->hour)), 0);

        $plans = [
            ['class' => $classes[0], 'start' => $base->subHours(3), 'state' => 'finished'],
            ['class' => $classes[4], 'start' => $local->subMinutes(20)->startOfMinute(), 'state' => 'in_progress'],
            ['class' => $classes[1], 'start' => $base->addHours(2), 'state' => 'upcoming'],
            ['class' => $classes[5], 'start' => $base->addHours(3), 'state' => 'cancelled'],
        ];

        foreach ($plans as $plan) {
            /** @var ClassGroup $group */
            $group = $plan['class'];
            $startsAt = $plan['start']->utc();

            $lesson = Lesson::firstOrCreate(
                ['class_group_id' => $group->id, 'starts_at' => $startsAt],
                [
                    'tutor_id' => $group->tutor_id,
                    'ends_at' => $startsAt->addMinutes($group->duration_minutes),
                    'status' => match ($plan['state']) {
                        'finished' => LessonStatus::Completed,
                        'in_progress' => LessonStatus::InProgress,
                        'cancelled' => LessonStatus::Cancelled,
                        default => LessonStatus::Scheduled,
                    },
                    'cancelled_reason' => $plan['state'] === 'cancelled' ? 'Tutor unwell' : null,
                ],
            );

            if ($plan['state'] === 'finished') {
                $this->register($lesson, $group, full: true, tutorLateChance: 0);
                $this->reports($lesson, $now);
            }

            if ($plan['state'] === 'in_progress') {
                $tutor = $lesson->tutor;
                app(RecordAttendance::class)->handle($lesson, $tutor, AttendanceStatus::Present, $tutor, $lesson->starts_at->addMinutes(6));
                $this->register($lesson, $group, full: false, tutorLateChance: 0);
            }
        }
    }

    /**
     * Mark the tutor (unless already recorded) and the students. Partial registers mark about half.
     */
    private function register(Lesson $lesson, ClassGroup $group, bool $full, int $tutorLateChance): void
    {
        $record = app(RecordAttendance::class);
        $tutor = $lesson->tutor;

        $record->handle(
            $lesson,
            $tutor,
            AttendanceStatus::Present,
            $tutor,
            mt_rand(1, 100) <= $tutorLateChance ? $lesson->starts_at->addMinutes(mt_rand(3, 15)) : $lesson->starts_at->subMinutes(mt_rand(0, 10)),
            onlyIfUnrecorded: true,
        );

        foreach ($group->activeStudents()->get() as $index => $student) {
            if (! $full && $index % 2 === 1) {
                continue;
            }

            $roll = mt_rand(1, 100);
            $reliablyLate = $student->name === self::RELIABLY_LATE_STUDENT;

            [$status, $arrivedAt] = match (true) {
                $reliablyLate || $roll <= 10 => [AttendanceStatus::Present, $lesson->starts_at->addMinutes(mt_rand(2, 25))],
                $roll <= 88 => [AttendanceStatus::Present, $lesson->starts_at->subMinutes(mt_rand(0, 5))],
                $roll <= 96 => [AttendanceStatus::Absent, null],
                default => [AttendanceStatus::Excused, null],
            };

            $record->handle($lesson, $student, $status, $tutor, $arrivedAt, onlyIfUnrecorded: true);
        }
    }

    /**
     * The tutor reports on most lessons; some students who attended do too.
     */
    private function reports(Lesson $lesson, CarbonImmutable $now): void
    {
        $submittedAt = $lesson->ends_at->addMinutes(mt_rand(5, 120));
        $submittedAt = $submittedAt->greaterThan($now) ? $now : $submittedAt;

        if (mt_rand(1, 100) <= 70) {
            LessonReport::firstOrCreate(
                ['lesson_id' => $lesson->id, 'author_id' => $lesson->tutor_id],
                [
                    'author_role' => AttendeeRole::Tutor,
                    'rating' => mt_rand(3, 5),
                    'went_well' => self::TUTOR_WENT_WELL[array_rand(self::TUTOR_WENT_WELL)],
                    'issues' => mt_rand(1, 100) <= 30 ? 'A couple of students arrived late and missed the starter.' : null,
                    'recommendations' => self::TUTOR_RECOMMENDATIONS[array_rand(self::TUTOR_RECOMMENDATIONS)],
                    'submitted_at' => $submittedAt,
                ],
            );
        }

        $attended = $lesson->attendanceRecords()
            ->where('attendee_role', AttendeeRole::Student)
            ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])
            ->pluck('user_id');

        foreach ($attended as $studentId) {
            if (mt_rand(1, 100) > 25) {
                continue;
            }

            LessonReport::firstOrCreate(
                ['lesson_id' => $lesson->id, 'author_id' => $studentId],
                [
                    'author_role' => AttendeeRole::Student,
                    'rating' => mt_rand(2, 5),
                    'went_well' => self::STUDENT_WENT_WELL[array_rand(self::STUDENT_WENT_WELL)],
                    'issues' => mt_rand(1, 100) <= 40 ? self::STUDENT_ISSUES[array_rand(self::STUDENT_ISSUES)] : null,
                    'recommendations' => null,
                    'submitted_at' => $submittedAt,
                ],
            );
        }
    }

    /**
     * The admin has followed up on most lateness older than a week.
     */
    private function followUps(User $admin, CarbonImmutable $now): void
    {
        $cutoff = $now->setTimezone(config('academy.timezone'))->subWeek()->toDateString();

        LatenessReport::query()
            ->where('lesson_date', '<', $cutoff)
            ->whereNull('reviewed_at')
            ->get()
            ->each(function (LatenessReport $report) use ($admin, $now): void {
                if (mt_rand(1, 100) > 60) {
                    return;
                }

                $report->update([
                    'follow_up_notes' => $report->attendee_role === AttendeeRole::Tutor
                        ? 'Discussed punctuality with the tutor.'
                        : 'Spoke to the parent about arriving on time.',
                    'reviewed_by_id' => $admin->id,
                    'reviewed_at' => $now->subDays(mt_rand(1, 6)),
                ]);
            });
    }

    private function reliablyLateTutorId(): int
    {
        return User::where('email', self::TUTORS[self::RELIABLY_LATE_TUTOR]['email'])->value('id');
    }
}
