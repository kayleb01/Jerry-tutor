<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'role:admin,tutor'])
        ->get('/_test/staff-only', fn () => 'ok');
});

it('allows users with a listed role', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get('/_test/staff-only')
        ->assertOk();
})->with(['admin', 'tutor']);

it('forbids users without a listed role', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get('/_test/staff-only')
        ->assertForbidden();
})->with(['student', 'guardian']);
