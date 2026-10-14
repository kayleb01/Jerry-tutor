<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\GuardianNotice;
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
            ->where('reports.data.1.date', 'Mon 20 Oct')
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

it('warns about repeat lateness: 3 or more in the last 30 days, students and tutors alike', function () {
    foreach (['2025-10-06', '2025-10-13', '2025-10-20'] as $date) {
        $avaLatest = ($this->lateOn)($date, $this->ava);
    }
    foreach (['2025-09-30', '2025-10-14', '2025-10-21'] as $date) {  // 30 Sept is 31 days ago
        $tutorLatest = ($this->lateOn)($date, $this->tutor);
    }

    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['report' => $avaLatest->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $avaLatest->id)->where('selected.repeatCount', 3));

    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['report' => $tutorLatest->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.repeatCount', 2)->where('selected.roleLabel', 'Tutor'));
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

it('keeps follow-up admin-only', function (string $state) {
    $report = ($this->lateOn)('2025-10-20', $this->ava);

    $this->actingAs(User::factory()->{$state}()->create())
        ->put(route('admin.lateness.update', $report), ['follow_up_notes' => 'x', 'reviewed' => true])
        ->assertForbidden();

    expect($report->fresh()->reviewed_at)->toBeNull();
})->with(['tutor', 'student', 'guardian']);

it('includes reports on the last day of the date range', function () {
    ($this->lateOn)('2025-10-20', $this->ava);

    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['from' => '2025-10-20', 'to' => '2025-10-20']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 1));
});

it('lists new reports by default, with counts, and selects the newest', function () {
    $older = ($this->lateOn)('2025-10-20', $this->ava);
    $followed = ($this->lateOn)('2025-10-27', $this->ava);
    $followed->update(['reviewed_at' => now()]);

    $this->actingAs($this->admin)->get(route('admin.lateness.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports.data', 1)
            ->where('reports.data.0.id', $older->id)
            ->where('reports.data.0.status', 'new')
            ->where('counts', ['new' => 1, 'followed' => 1, 'all' => 2])
            ->where('filters.status', 'new')
            ->where('filters.report', null)
            ->where('selected.id', $older->id));

    $this->actingAs($this->admin)->get(route('admin.lateness.index', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('reports.data', 2)->where('selected.id', $followed->id));
});

it('follows up and moves to the next report with the same filters', function () {
    $report = ($this->lateOn)('2025-10-27', $this->ava);
    $next = ($this->lateOn)('2025-10-20', $this->ava);

    $this->actingAs($this->admin)
        ->from(route('admin.lateness.index', ['status' => 'new', 'role' => 'student', 'report' => $report->id, 'page' => 2, 'evil' => 1]))
        ->put(route('admin.lateness.update', $report), ['follow_up_notes' => 'Spoke to mum.', 'reviewed' => true, 'next' => $next->id])
        ->assertRedirect(route('admin.lateness.index', ['status' => 'new', 'role' => 'student', 'report' => $next->id]));

    expect($report->fresh()->reviewed_at)->not->toBeNull();
});

it('notifies a student\'s guardians and counts it as the follow-up', function () {
    $report = ($this->lateOn)('2025-10-20', $this->ava);
    $report->update(['follow_up_notes' => 'Called home.']);
    $mum = User::factory()->guardian()->create(['name' => 'Mrs Bello']);
    $dad = User::factory()->guardian()->create(['name' => 'Mr Bello']);
    $mum->linkChild($this->ava, 'Mother');
    $dad->linkChild($this->ava, 'Father');

    $this->actingAs($this->admin)->post(route('admin.lateness.notify-parent', $report), ['note' => ''])
        ->assertSessionHasErrors(['note']);

    $this->actingAs($this->admin)
        ->from(route('admin.lateness.index', ['report' => $report->id]))
        ->post(route('admin.lateness.notify-parent', $report), ['note' => 'Ava arrived 7 minutes late.'])
        ->assertRedirect(route('admin.lateness.index', ['report' => $report->id]))
        ->assertInertiaFlash('toast.message', 'Sent to Mr Bello and Mrs Bello.');

    $notice = GuardianNotice::sole();

    expect($notice->student_id)->toBe($this->ava->id)
        ->and($notice->lateness_report_id)->toBe($report->id)
        ->and($notice->sent_by_id)->toBe($this->admin->id)
        ->and($report->fresh()->reviewed_at)->not->toBeNull()
        ->and($report->fresh()->follow_up_notes)->toBe('Called home.');
});

it('refuses to notify parents for tutors or students without a guardian', function () {
    $tutorReport = ($this->lateOn)('2025-10-20', $this->tutor);
    $studentReport = ($this->lateOn)('2025-10-21', $this->ava);

    $this->actingAs($this->admin)->post(route('admin.lateness.notify-parent', $tutorReport), ['note' => 'Hello'])->assertStatus(422);
    $this->actingAs($this->admin)->post(route('admin.lateness.notify-parent', $studentReport), ['note' => 'Hello'])->assertStatus(422);
    $this->actingAs(User::factory()->tutor()->create())->post(route('admin.lateness.notify-parent', $studentReport), ['note' => 'Hello'])->assertForbidden();

    expect(GuardianNotice::count())->toBe(0);
});

it('exports only the chosen status', function () {
    ($this->lateOn)('2025-10-20', $this->ava);
    ($this->lateOn)('2025-10-21', $this->ava)->update(['reviewed_at' => now()]);

    $lines = array_values(array_filter(explode("\n", $this->actingAs($this->admin)->get(route('admin.lateness.export', ['status' => 'followed']))->streamedContent())));

    expect($lines)->toHaveCount(2)->and($lines[1])->toContain('2025-10-21');
});

it('shows admins how many lateness reports are new in the sidebar', function () {
    ($this->lateOn)('2025-10-20', $this->ava);
    ($this->lateOn)('2025-10-21', $this->tutor);

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('badges.lateness', 2));
});
