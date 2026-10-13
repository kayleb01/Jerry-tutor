<?php

use Inertia\Testing\AssertableInertia as Assert;

it('shows the branded landing page with sign-up enabled', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', true));
});
