<?php

use App\Enums\ApprovalStatus;
use App\Models\Lesson;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends a pending tutor to the waiting page from any signed-in page', function (string $path) {
    $tutor = User::factory()->pendingTutor()->create();
    $lesson = Lesson::factory()->create();

    $this->actingAs($tutor)->get(str_replace('{lesson}', (string) $lesson->id, $path))
        ->assertRedirect(route('tutors.application'));

    $this->actingAs($tutor)->get(route('tutors.application'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/tutor-application-status')->where('status', 'pending'));
})->with(['/dashboard', '/take-register', '/report', '/lessons/{lesson}/register', '/lessons/{lesson}/report']);

it('keeps a pending tutor out of write routes too', function () {
    $tutor = User::factory()->pendingTutor()->create();
    $lesson = Lesson::factory()->create();

    $this->actingAs($tutor)->post(route('lessons.check-in', $lesson))
        ->assertRedirect(route('tutors.application'));
});

it('shows a rejected tutor the not-approved page', function () {
    $tutor = User::factory()->rejectedTutor()->create();

    $this->actingAs($tutor)->get(route('dashboard'))->assertRedirect(route('tutors.application'));
    $this->actingAs($tutor)->get(route('tutors.application'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'rejected'));
});

it('keeps out a tutor with no approval decision at all', function () {
    $tutor = User::factory()->tutor()->create(['approval_status' => null]);

    $this->actingAs($tutor)->get(route('dashboard'))->assertRedirect(route('tutors.application'));
    $this->actingAs($tutor)->get(route('tutors.application'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/tutor-application-status')->where('status', 'pending'));
});

it('lets a pending tutor log out', function () {
    $this->actingAs(User::factory()->pendingTutor()->create())
        ->post(route('logout'))
        ->assertRedirect();

    $this->assertGuest();
});

it('lets approved tutors and other roles through', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('tutors.application'))->assertRedirect(route('dashboard'));
})->with(['tutor', 'admin', 'guardian']);

it('still requires a verified email for the status page', function () {
    $this->actingAs(User::factory()->pendingTutor()->unverified()->create())
        ->get(route('tutors.application'))
        ->assertRedirect(route('verification.notice'));
});

it('approves tutors an admin creates', function () {
    $this->actingAs(User::factory()->admin()->create())->post(route('admin.users.store'), [
        'name' => 'New Tutor',
        'email' => 'new.tutor@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
        'role' => 'tutor',
    ])->assertSessionHasNoErrors();

    $tutor = User::where('email', 'new.tutor@example.com')->firstOrFail();
    expect($tutor->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($tutor->isApprovedTutor())->toBeTrue();
});

it('leaves approval status empty for non-tutors an admin creates', function () {
    $this->actingAs(User::factory()->admin()->create())->post(route('admin.users.store'), [
        'name' => 'Other Admin',
        'email' => 'other.admin@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
        'role' => 'admin',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'other.admin@example.com')->firstOrFail()->approval_status)->toBeNull();
});

it('never lets approval status be mass assigned', function () {
    $user = new User(['name' => 'X', 'approval_status' => 'approved', 'role' => 'tutor']);

    expect($user->approval_status)->toBeNull();
});

it('approves every tutor that existed before', function () {
    $file = collect(glob(database_path('migrations/*add_approval_status_to_users_table.php')))->first();
    $migration = require $file;

    $tutor = User::factory()->tutor()->create();
    $deleted = tap(User::factory()->tutor()->create())->delete();
    $guardian = User::factory()->guardian()->create();

    $migration->down();
    $migration->up();

    expect($tutor->fresh()->approval_status)->toBe(ApprovalStatus::Approved)
        ->and(User::withTrashed()->find($deleted->id)->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($guardian->fresh()->approval_status)->toBeNull();
});
