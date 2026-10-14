<?php

use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('publishes a reviewed question and records who and when, once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC'));
    $question = Question::factory()->inReview()->create();

    $this->actingAs($this->admin)->put(route('admin.questions.status.update', $question), ['status' => 'published'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Question moved to Published.');

    expect($question->fresh())
        ->status->toBe(QuestionStatus::Published)
        ->reviewed_by_id->toBe($this->admin->id)
        ->published_at->toDateTimeString()->toBe('2026-10-14 09:00:00');

    $this->travel(2)->days();
    $question->fresh()->update(['status' => QuestionStatus::InReview]);
    $this->actingAs($this->admin)->put(route('admin.questions.status.update', $question), ['status' => 'published']);

    expect($question->fresh()->published_at->toDateTimeString())->toBe('2026-10-14 09:00:00');
});

it('refuses a jump the workflow does not allow', function () {
    $question = Question::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.questions.status.update', $question), ['status' => 'published'])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', "A Draft question can't be moved to Published.");

    expect($question->fresh()->status)->toBe(QuestionStatus::Draft);
});

it('moves the valid questions in bulk and reports the skipped ones', function () {
    $drafts = Question::factory()->count(2)->create();
    $published = Question::factory()->published()->create();

    $this->actingAs($this->admin)->put(route('admin.questions.bulk-status'), [
        'ids' => [...$drafts->pluck('id'), $published->id],
        'status' => 'in_review',
    ])->assertInertiaFlash('toast.message', "2 questions moved to In review. 1 skipped because its status doesn't allow that.");

    expect($drafts->map->fresh()->pluck('status')->unique()->all())->toBe([QuestionStatus::InReview])
        ->and($published->fresh()->status)->toBe(QuestionStatus::Published);
});

it('validates bulk input', function () {
    $this->actingAs($this->admin)->put(route('admin.questions.bulk-status'), ['ids' => [], 'status' => 'nope'])
        ->assertSessionHasErrors(['ids', 'status']);
});

it('is only for admins', function () {
    $question = Question::factory()->inReview()->create();

    $this->actingAs(User::factory()->tutor()->create())
        ->put(route('admin.questions.status.update', $question), ['status' => 'published'])
        ->assertForbidden();
});
