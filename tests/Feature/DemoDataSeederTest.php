<?php

use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    // Wednesday 15 October 2025, 13:30 in Lagos.
    $this->travelTo(CarbonImmutable::parse('2025-10-15 12:30:00', 'UTC'));
    $this->seed(DemoDataSeeder::class);
    $this->admin = User::firstWhere('email', 'admin@academy.test');
});

it('creates staff, families, students across every key stage and their classes', function () {
    expect($this->admin->role)->toBe(Role::Admin)
        ->and(User::where('role', Role::Tutor)->count())->toBeGreaterThanOrEqual(4)
        ->and(User::where('role', Role::Student)->count())->toBeGreaterThanOrEqual(24)
        ->and(User::where('role', Role::Guardian)->count())->toBeGreaterThanOrEqual(8)
        ->and(ClassGroup::count())->toBeGreaterThanOrEqual(6);

    $years = User::where('role', Role::Student)->with('studentProfile')->get()->map(fn (User $student) => $student->studentProfile->year_group->value)->unique();
    expect($years->min())->toBe(3)->and($years->max())->toBe(11);

    ClassGroup::all()->each(fn (ClassGroup $group) => expect($group->activeStudents()->count())->toBeGreaterThan(0));
});

it('fills six weeks of history with attendance, lateness and reports', function () {
    expect(Lesson::where('starts_at', '<', now()->subWeeks(5))->exists())->toBeTrue()
        ->and(AttendanceRecord::count())->toBeGreaterThan(150)
        ->and(LatenessReport::count())->toBeGreaterThan(10)
        ->and(LatenessReport::whereNotNull('reviewed_at')->exists())->toBeTrue()
        ->and(LessonReport::where('author_role', 'tutor')->exists())->toBeTrue()
        ->and(LessonReport::where('author_role', 'student')->exists())->toBeTrue()
        ->and(Lesson::where('starts_at', '<', now())->where('status', LessonStatus::Scheduled)->where('ends_at', '<', now()->subHour())->exists())->toBeFalse();
});

it('gives the admin dashboard something in every section', function () {
    $this->actingAs($this->admin)->get(route('dashboard', ['period' => 'last_30']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/admin')
            ->where('stats.classesToday', fn (int $count) => $count >= 4)
            ->where('stats.runningNow', fn (int $count) => $count >= 1)
            ->where('stats.toStart', fn (int $count) => $count >= 1)
            ->where('stats.attendance', fn (?int $value) => $value !== null)
            ->where('stats.late', fn (int $count) => $count >= 1)
            ->where('today', fn ($today) => collect($today)->pluck('state')->contains('cancelled') && collect($today)->pluck('state')->contains('finished'))
            ->where('repeatLateness', fn ($rows) => count($rows) >= 2)
            ->where('latestReports', fn ($rows) => count($rows) >= 1)
            ->etc());
});

it('can run again without duplicating anything', function () {
    $users = User::count();
    $lessons = Lesson::count();

    $this->seed(DemoDataSeeder::class);

    expect(User::count())->toBe($users)->and(Lesson::count())->toBe($lessons);
});

it('lets every demo account log in with the password "password"', function () {
    auth()->logout();

    $this->post(route('login.store'), ['login' => 'tutor.adaeze@academy.test', 'password' => 'password'])
        ->assertRedirect('/dashboard');
});
