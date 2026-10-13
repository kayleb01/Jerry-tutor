<?php

use App\Models\User;

it('logs the user out and returns them to the landing page', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});
