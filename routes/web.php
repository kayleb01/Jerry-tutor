<?php

use App\Http\Controllers\ChildController;
use App\Http\Controllers\ChildPasswordController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

Route::middleware(['auth', 'verified', 'role:guardian'])->group(function () {
    Route::resource('children', ChildController::class)->except(['show', 'destroy']);
    Route::put('children/{child}/password', ChildPasswordController::class)->name('children.password.update');
});

if (app()->isLocal()) {
    Route::inertia('/styleguide', 'styleguide')->name('styleguide');
}
