<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2025-10-31 12:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
    $this->group = ClassGroup::factory()->create(['name' => 'Year 5 Maths']);
    $this->tutor = $this->group->tutor;
    $this->tutor->update(['name' => 'Tunde Tutor']);
    $this->ava = User::factory()->student()->create(['name' => 'Ava']);
    $this->group->enrol($this->ava);
    $this->lateOn = function (string $date, User $who, int $minutes = 7, ?ClassGroup $group = null) {
        $group ??= $this->group;
        $lesson = Lesson::factory()->for($group)->create([
            'tutor_id' => $group->tutor_id,
            'starts_at' => CarbonImmutable::parse("{$date} 09:00:00", 'UTC'),
            'ends_at' => CarbonImmutable::parse("{$date} 10:00:00", 'UTC'),
        ]);

        return app(RecordAttendance::class)->handle($lesson, $who, AttendanceStatus::Present, null, CarbonImmutable::parse("{$date} 09:00:00", 'UTC')->addMinutes($minutes))->latenessReport;
    };
});

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())->get(route('admin.lateness.index'))->assertForbidden();
})->with(['tutor', 'student', 'guardian']);

it('lists lateness for students and tutors in academy time, newest first', function () {
    ($this->lateOn)('2025-10-20', $this->ava, 7);
    ($this->lateOn)('2025-10-27', $this->tutor, 12);

    $this->actingAs($this->admin)->get(route('admin.lateness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/lateness/index')
            ->has('reports.data', 2)
            ->where('reports.data.0.role', 'tutor')
            ->where('reports.data.0.minutesLate', 12)
            ->where('reports.data.1.name', 'Ava')
            ->where('reports.data.1.date', 'Mon 20 Oct 2025')
            ->where('reports.data.1.scheduled', '10:00')
            ->where('reports.data.1.arrived', '10:07')
            ->where('reports.data.1.className', 'Year 5 Maths'));
});

it('filters by role, class, date range and name', function () {
    $other = ClassGroup::factory()->create(['name' => 'Year 7 Maths']);
    $bola = User::factory()->student()->create(['name' => 'Bola']);
    $other->enrol($bola);
    ($this->lateOn)('2025-10-06', $this->ava);
    ($this->lateOn)('2025-10-13', $this->tutor);
    ($this->lateOn)('2025-10-20', $bola, 5, $other);

    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['role' => 'tutor']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.role', 'tutor'));
    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['class' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.name', 'Bola'));
    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['from' => '2025-10-10', 'to' => '2025-10-15']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.role', 'tutor'));
    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['search' => 'av']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1)->where('reports.data.0.name', 'Ava'));
    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['search' => '%']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 0));
});

it('flags repeat lateness: 3 or more in the last 30 days, students and tutors alike', function () {
    foreach (['2025-10-06', '2025-10-13', '2025-10-20'] as $date) {
        ($this->lateOn)($date, $this->ava);
    }
    foreach (['2025-09-30', '2025-10-14', '2025-10-21'] as $date) {  // 30 Sept is 31 days ago
        ($this->lateOn)($date, $this->tutor);
    }
    $tutor2 = User::factory()->tutor()->create(['name' => 'Tola']);
    $group2 = ClassGroup::factory()->for($tutor2, 'tutor')->create();
    foreach (['2025-10-14', '2025-10-21', '2025-10-28'] as $date) {
        ($this->lateOn)($date, $tutor2, 3, $group2);
    }

    $this->actingAs($this->admin)->get(route('admin.lateness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('repeat', 2)
            ->where('repeat.0.name', 'Tola')
            ->where('repeat.0.role', 'tutor')
            ->where('repeat.0.count', 3)
            ->where('repeat.0.lastDate', 'Tue 28 Oct')
            ->where('repeat.1.name', 'Ava'));
});

it('records a follow-up and marks the report reviewed', function () {
    $report = ($this->lateOn)('2025-10-20', $this->ava);

    $this->actingAs($this->admin)->put(route('admin.lateness.update', $report), ['follow_up_notes' => 'Spoke to parent.', 'reviewed' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $report->refresh();
    expect($report->follow_up_notes)->toBe('Spoke to parent.')
        ->and($report->reviewed_by_id)->toBe($this->admin->id)
        ->and($report->reviewed_at)->not->toBeNull();

    $this->actingAs($this->admin)->put(route('admin.lateness.update', $report), ['follow_up_notes' => 'Spoke to parent.', 'reviewed' => false]);
    expect($report->fresh()->reviewed_at)->toBeNull();
});

it('exports the filtered list as CSV, neutralising formulas', function () {
    $evil = ClassGroup::factory()->create(['name' => '=HYPERLINK("http://x","click")']);
    $bola = User::factory()->student()->create(['name' => 'Bola']);
    $evil->enrol($bola);
    ($this->lateOn)('2025-10-20', $bola, 9, $evil);
    ($this->lateOn)('2025-10-21', $this->tutor);

    $response = $this->actingAs($this->admin)->get(route('admin.lateness.export', ['role' => 'student']));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('Date,Name,Role,Class')
        ->and($lines[1])->toContain('Bola')
        ->and($lines[1])->toContain("'=HYPERLINK")
        ->and($lines[1])->toContain(',9,');
});

it('keeps exporting admin-only', function () {
    $this->actingAs(User::factory()->tutor()->create())->get(route('admin.lateness.export'))->assertForbidden();
});
