<?php

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Models\ClassGroup;
use App\Models\Subject;
use App\Models\User;
use App\Support\FormTimer;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->class = ClassGroup::factory()->create(['name' => 'Year 5']);
    $this->subject = Subject::factory()->create(['name' => 'Science']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tutorApplication(array $overrides = []): array
{
    return [
        'name' => 'Ngozi Okoye',
        'email' => 'Ngozi@Example.com ',
        'phone' => '08012345678',
        'password' => 'a-Strong-pass-123',
        'password_confirmation' => 'a-Strong-pass-123',
        'classes' => [['class_group_id' => test()->class->id, 'subject_id' => test()->subject->id]],
        'website' => '',
        'form_started' => FormTimer::issue(),
        ...$overrides,
    ];
}

it('creates a pending tutor with their requested classes and sends a verification email', function () {
    Notification::fake();

    $this->get(route('tutors.join'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/tutor-join')
            ->has('classOptions', 1)
            ->where('subjectOptions', fn ($options) => collect($options)->contains('label', 'Science'))
            ->has('formStarted'));

    $data = tutorApplication();
    $this->travel(5)->seconds();

    $this->post(route('tutors.join.store'), $data)->assertRedirect(route('verification.notice'));

    $tutor = User::where('email', 'ngozi@example.com')->firstOrFail();
    expect($tutor->role)->toBe(Role::Tutor)
        ->and($tutor->approval_status)->toBe(ApprovalStatus::Pending)
        ->and($tutor->email_verified_at)->toBeNull()
        ->and($tutor->phone)->toBe('08012345678')
        ->and($tutor->requestedClasses)->toHaveCount(1)
        ->and($tutor->requestedClasses->first()->class_group_id)->toBe($this->class->id)
        ->and($tutor->requestedClasses->first()->subject_id)->toBe($this->subject->id);
    $this->assertAuthenticatedAs($tutor);
    Notification::assertSentTo($tutor, VerifyEmail::class);
});

it('rejects a filled honeypot or a submit faster than 3 seconds', function () {
    $this->travel(5)->seconds();

    $this->post(route('tutors.join.store'), tutorApplication(['website' => 'http://spam.test']))
        ->assertSessionHasErrors(['form' => 'Something went wrong, please try again.']);

    $this->post(route('tutors.join.store'), tutorApplication(['form_started' => null]))
        ->assertSessionHasErrors(['form' => 'Something went wrong, please try again.']);

    $this->post(route('tutors.join.store'), tutorApplication(['form_started' => 'garbled']))
        ->assertSessionHasErrors(['form' => 'Something went wrong, please try again.']);

    $fast = FormTimer::issue();
    $this->travel(1)->seconds();
    $this->post(route('tutors.join.store'), tutorApplication(['form_started' => $fast]))
        ->assertSessionHasErrors(['form' => 'Something went wrong, please try again.']);

    expect(User::count())->toBe(0);
});

it('rate limits sign-ups to 5 a minute', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('tutors.join.store'), ['website' => 'bot'])->assertRedirect();
    }

    $this->post(route('tutors.join.store'), ['website' => 'bot'])->assertTooManyRequests();
});

it('rejects inactive classes or subjects and duplicate pairs', function () {
    $this->travel(5)->seconds();
    $inactiveClass = ClassGroup::factory()->create(['is_active' => false]);
    $inactiveSubject = Subject::factory()->inactive()->create();
    $pair = ['class_group_id' => $this->class->id, 'subject_id' => $this->subject->id];

    $this->post(route('tutors.join.store'), tutorApplication([
        'classes' => [['class_group_id' => $inactiveClass->id, 'subject_id' => $this->subject->id]],
    ]))->assertSessionHasErrors('classes.0.class_group_id');

    $this->post(route('tutors.join.store'), tutorApplication([
        'classes' => [['class_group_id' => $this->class->id, 'subject_id' => $inactiveSubject->id]],
    ]))->assertSessionHasErrors('classes.0.subject_id');

    $this->post(route('tutors.join.store'), tutorApplication(['classes' => [$pair, $pair]]))
        ->assertSessionHasErrors('classes.1');

    $this->post(route('tutors.join.store'), tutorApplication(['classes' => []]))
        ->assertSessionHasErrors('classes');

    expect(User::count())->toBe(0);
});

it('rejects an email already used, including by a deactivated account', function () {
    $this->travel(5)->seconds();
    User::factory()->tutor()->create(['email' => 'ngozi@example.com'])->delete();

    $this->post(route('tutors.join.store'), tutorApplication())->assertSessionHasErrors('email');

    expect(User::withTrashed()->count())->toBe(1);
});

it('is reachable only by guests', function () {
    $user = User::factory()->tutor()->create();

    $this->actingAs($user)->get(route('tutors.join'))->assertRedirect();
    $this->actingAs($user)->post(route('tutors.join.store'), tutorApplication())->assertRedirect();
    expect(User::count())->toBe(1);
});
