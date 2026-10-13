<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the forgot-password page', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password'));
});

it('emails a reset link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'parent@example.com']);

    $this->post(route('password.email'), ['email' => 'parent@example.com'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('shows the reset page with the token and email', function () {
    $this->get(route('password.reset', ['token' => 'abc123', 'email' => 'parent@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/reset-password')
            ->where('token', 'abc123')
            ->where('email', 'parent@example.com'));
});

it('resets the password with a valid token', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'parent@example.com']);
    $this->post(route('password.email'), ['email' => 'parent@example.com']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertRedirect(route('login'));

        return Hash::check('a-new-strong-password', $user->fresh()->password);
    });
});
