<?php

use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AttendanceStats;
use Carbon\CarbonImmutable;

it('summarises the last 30 days, leaving out excused lessons', function () {
    $this->travelTo(CarbonImmutable::parse('2025-10-15 12:00:00', 'UTC'));
    $group = ClassGroup::factory()->create();
    $ava = User::factory()->student()->create();
    $group->enrol($ava);
    $at = fn (string $start) => Lesson::factory()->for($group)->create(['starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHour()]);
    $record = app(RecordAttendance::class);

    expect(app(AttendanceStats::class)->forUser($ava))->toBe(['attendance' => null, 'onTime' => null, 'late' => 0, 'absent' => 0]);

    $record->handle($at('2025-10-01 09:00:00'), $ava, AttendanceStatus::Present, null);
    $record->handle($at('2025-10-04 09:00:00'), $ava, AttendanceStatus::Present, null, CarbonImmutable::parse('2025-10-04 09:10:00', 'UTC'));
    $record->handle($at('2025-10-08 09:00:00'), $ava, AttendanceStatus::Absent, null);
    $record->handle($at('2025-10-11 09:00:00'), $ava, AttendanceStatus::Excused, null);
    $record->handle($at('2025-09-01 09:00:00'), $ava, AttendanceStatus::Absent, null);

    expect(app(AttendanceStats::class)->forUser($ava))->toBe(['attendance' => 67, 'onTime' => 50, 'late' => 1, 'absent' => 1]);
});
