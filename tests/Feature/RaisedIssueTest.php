<?php

use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\RaisedIssue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['academy.timezone' => 'Africa/Lagos']);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $this->tutor = User::factory()->tutor()->create();
    $this->admin = User::factory()->admin()->create(['name' => 'Mrs J. Great']);
    $group = ClassGroup::factory()->for($this->tutor, 'tutor')->create(['name' => 'Year 4 Number']);
    $startsAt = CarbonImmutable::parse('2026-10-13 13:00:00', 'UTC');
    $this->lesson = Lesson::factory()->for($group)->create(['starts_at' => $startsAt, 'ends_at' => $startsAt->addHour()]);
});

it('shows a tutor only their own unread notes from the academy', function () {
    $mine = RaisedIssue::factory()->for($this->lesson)->create(['note' => 'Please recap place value for Tolu.', 'raised_by_id' => $this->admin->id]);
    RaisedIssue::factory()->for($this->lesson)->acknowledged()->create();
    RaisedIssue::factory()->create();

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('fromAcademy', 1)
            ->where('fromAcademy.0', [
                'id' => $mine->id,
                'className' => 'Year 4 Number',
                'when' => 'Tue 13 Oct',
                'note' => 'Please recap place value for Tolu.',
                'raisedBy' => 'Mrs J. Great',
            ]));
});

it('lets the tutor acknowledge their note once', function () {
    $issue = RaisedIssue::factory()->for($this->lesson)->create();

    $this->actingAs($this->tutor)->put(route('raised-issues.acknowledge', $issue))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect($issue->fresh()->acknowledged_at->toDateTimeString())->toBe('2026-10-14 12:00:00');

    $this->travel(1)->hour();
    $this->actingAs($this->tutor)->put(route('raised-issues.acknowledge', $issue));

    expect($issue->fresh()->acknowledged_at->toDateTimeString())->toBe('2026-10-14 12:00:00');

    $this->actingAs($this->tutor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('fromAcademy', 0));
});

it('keeps notes private to their tutor', function () {
    $issue = RaisedIssue::factory()->for($this->lesson)->create();

    $this->actingAs(User::factory()->tutor()->create())->put(route('raised-issues.acknowledge', $issue))->assertForbidden();
    $this->actingAs($this->admin)->put(route('raised-issues.acknowledge', $issue))->assertForbidden();
    $this->actingAs(User::factory()->student()->create())->put(route('raised-issues.acknowledge', $issue))->assertForbidden();

    expect($issue->fresh()->acknowledged_at)->toBeNull();
});
