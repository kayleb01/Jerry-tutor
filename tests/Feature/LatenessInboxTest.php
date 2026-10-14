<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\GuardianNotice;
use App\Models\LatenessReport;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos', 'academy.lateness_grace_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->group = ClassGroup::factory()->create(['name' => 'Year 4 Number']);
    $this->tolu = User::factory()->student()->create(['name' => 'Tolu Ade']);
    $this->group->enrol($this->tolu);
});

function lateFor(User $who, string $date, int $minutes = 12, ?ClassGroup $group = null): LatenessReport
{
    $group ??= test()->group;
    $startsAt = CarbonImmutable::parse("{$date} 13:00:00", 'UTC');
    $lesson = Lesson::factory()->for($group)->create(['tutor_id' => $group->tutor_id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour()]);

    return app(RecordAttendance::class)->handle($lesson, $who, AttendanceStatus::Present, null, $startsAt->addMinutes($minutes))->latenessReport;
}

it('stores notices to guardians against a lateness report', function () {
    $report = lateFor($this->tolu, '2026-10-13');
    $notice = GuardianNotice::factory()->create(['student_id' => $this->tolu->id, 'lateness_report_id' => $report->id]);

    expect($report->guardianNotices)->toHaveCount(1)
        ->and($notice->student->is($this->tolu))->toBeTrue()
        ->and($notice->acknowledged_at)->toBeNull()
        ->and(GuardianNotice::factory()->acknowledged()->create()->acknowledged_at)->toBeInstanceOf(CarbonImmutable::class);
});
