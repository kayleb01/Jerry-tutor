<?php

use App\Models\Lesson;
use App\Models\RaisedIssue;
use App\Models\User;
use Carbon\CarbonImmutable;

it('records reviews and raised issues on a lesson', function () {
    $admin = User::factory()->admin()->create();
    $lesson = Lesson::factory()->create();
    $lesson->forceFill(['reports_reviewed_at' => now(), 'reports_reviewed_by_id' => $admin->id])->save();
    $issue = RaisedIssue::factory()->for($lesson)->create();

    $lesson = $lesson->fresh();

    expect($lesson->reports_reviewed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($lesson->reportsReviewedBy->is($admin))->toBeTrue()
        ->and($lesson->raisedIssues)->toHaveCount(1)
        ->and($issue->tutor_id)->toBe($lesson->tutor_id)
        ->and($issue->acknowledged_at)->toBeNull()
        ->and(RaisedIssue::factory()->acknowledged()->create()->acknowledged_at)->toBeInstanceOf(CarbonImmutable::class);
});
