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
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->mum = User::factory()->guardian()->create(['name' => 'Mrs Ade']);
    $this->dad = User::factory()->guardian()->create(['name' => 'Mr Ade']);
    $this->tolu = User::factory()->student()->create(['name' => 'Tolu Ade']);
    $this->mum->linkChild($this->tolu, 'Mother');
    $this->dad->linkChild($this->tolu, 'Father');
    $this->admin = User::factory()->admin()->create(['name' => 'Mrs J. Great']);
});

it('shows guardians unread notices about their own children', function () {
    $notice = GuardianNotice::factory()->create(['student_id' => $this->tolu->id, 'sent_by_id' => $this->admin->id, 'note' => 'Tolu arrived 12 minutes late.']);
    GuardianNotice::factory()->acknowledged()->create(['student_id' => $this->tolu->id]);
    GuardianNotice::factory()->create();

    $this->actingAs($this->mum)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('fromAcademy', 1)
            ->where('fromAcademy.0', [
                'id' => $notice->id,
                'childName' => 'Tolu Ade',
                'when' => 'Wed 14 Oct',
                'note' => 'Tolu arrived 12 minutes late.',
                'sentBy' => 'Mrs J. Great',
            ]));
});

it('lets a linked guardian acknowledge a notice once, for the whole family', function () {
    $notice = GuardianNotice::factory()->create(['student_id' => $this->tolu->id]);

    $this->actingAs($this->mum)->put(route('guardian-notices.acknowledge', $notice))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $this->travel(1)->hour();
    $this->actingAs($this->dad)->put(route('guardian-notices.acknowledge', $notice));

    expect($notice->fresh()->acknowledged_at->toDateTimeString())->toBe('2026-10-14 12:00:00')
        ->and($notice->fresh()->acknowledged_by_id)->toBe($this->mum->id);

    $this->actingAs($this->dad)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('fromAcademy', 0));
});

it('keeps notices private to the child\'s guardians', function () {
    $notice = GuardianNotice::factory()->create(['student_id' => $this->tolu->id]);

    $this->actingAs(User::factory()->guardian()->create())->put(route('guardian-notices.acknowledge', $notice))->assertForbidden();
    $this->actingAs($this->admin)->put(route('guardian-notices.acknowledge', $notice))->assertForbidden();
    $this->actingAs(User::factory()->tutor()->create())->put(route('guardian-notices.acknowledge', $notice))->assertForbidden();
    $this->actingAs($this->tolu)->put(route('guardian-notices.acknowledge', $notice))->assertForbidden();

    $this->actingAs(User::factory()->guardian()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('fromAcademy', 0));

    expect($notice->fresh()->acknowledged_at)->toBeNull();
});

it('withdraws unread notices when the lateness is corrected away', function () {
    $group = ClassGroup::factory()->create();
    $group->enrol($this->tolu);
    $startsAt = CarbonImmutable::parse('2026-10-13 13:00:00', 'UTC');
    $lesson = Lesson::factory()->for($group)->create(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHour()]);
    $record = app(RecordAttendance::class);
    $report = $record->handle($lesson, $this->tolu, AttendanceStatus::Present, null, $startsAt->addMinutes(12))->latenessReport;
    GuardianNotice::factory()->create(['student_id' => $this->tolu->id, 'lateness_report_id' => $report->id]);

    $record->handle($lesson, $this->tolu, AttendanceStatus::Present, null, $startsAt);

    $this->actingAs($this->mum)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('fromAcademy', 0));
});
