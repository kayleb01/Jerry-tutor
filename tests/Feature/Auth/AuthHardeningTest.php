<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('does not reveal whether an email has an account', function () {
    Notification::fake();

    $unknown = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nobody@example.com']);

    $unknown->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    User::factory()->create(['email' => 'parent@example.com']);
    $known = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'parent@example.com']);

    $known->assertSessionHas('status', __('passwords.sent'));
});

it('still validates the email format', function () {
    $this->post(route('password.email'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});

it('rate-limits sign-ups from one address', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), ['name' => 'x']);
    }

    $this->post(route('register.store'), ['name' => 'x'])->assertTooManyRequests();
});

it('shows the confirm-password page instead of an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password'));
});
