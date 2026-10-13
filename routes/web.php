<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

if (app()->isLocal()) {
    Route::inertia('/styleguide', 'styleguide')->name('styleguide');
}
