<?php

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\User;
use App\Services\ClassReportInbox;
use App\Services\ReportsDue;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2025-10-15 11:00:00', 'UTC'));
    $this->group = ClassGroup::factory()->create();
    $this->tutor = $this->group->tutor;
    $this->lesson = Lesson::factory()->for($this->group)->create([
        'tutor_id' => $this->tutor->id,
        'starts_at' => CarbonImmutable::parse('2025-10-15 08:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2025-10-15 09:00:00', 'UTC'),
    ]);
    AttendanceRecord::factory()->for($this->lesson)->create(['status' => AttendanceStatus::Present]);
    $this->draft = LessonReport::factory()->for($this->lesson)->draft()->create(['author_id' => $this->tutor->id]);
});

it('keeps tutor drafts away from the admin class reports inbox and its counts', function () {
    $inbox = app(ClassReportInbox::class);
    $filters = $inbox->filters([]);

    expect($inbox->counts($filters)['review'])->toBe(0);
    expect($inbox->query($inbox->filters(['queue' => 'missing']))->pluck('id')->all())->toBe([$this->lesson->id]);
});

it('leaves drafts out of the admin dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.0.tutorReported', false)
            ->has('latestReports', 0));
});

it('still asks for a report when only a draft exists', function () {
    expect(app(ReportsDue::class)->for($this->tutor))->toHaveCount(1);
});

it('separates submitted reports from drafts', function () {
    expect($this->draft->isDraft())->toBeTrue();
    expect($this->lesson->lessonReports)->toHaveCount(0);
    expect($this->lesson->reportsIncludingDrafts)->toHaveCount(1);
    expect(LessonReport::query()->submitted()->count())->toBe(0);
});
