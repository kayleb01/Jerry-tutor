<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

it('emails a verification link after sign-up', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Ngozi Okafor',
        'email' => 'ngozi@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ]);

    Notification::assertSentTo(User::firstWhere('email', 'ngozi@example.com'), VerifyEmail::class);
});

it('shows the verification notice to unverified users', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/verify-email'));
});

it('verifies the email from the signed link', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('treats a student without an email as verified and never emails them', function () {
    Notification::fake();
    $student = User::factory()->student()->unverified()->create(['email' => null]);

    expect($student->hasVerifiedEmail())->toBeTrue();

    $student->sendEmailVerificationNotification();

    Notification::assertNothingSent();
});
