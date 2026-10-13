<?php

use App\Http\Controllers\Admin\ClassEnrolmentController;
use App\Http\Controllers\Admin\ClassGroupController;
use App\Http\Controllers\Admin\RestoreUserController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\ChildController;
use App\Http\Controllers\ChildPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonReportController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\TutorCheckInController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::post('/lessons/{lesson}/check-in', TutorCheckInController::class)->name('lessons.check-in');
    Route::get('/lessons/{lesson}/register', [RegisterController::class, 'show'])->name('lessons.register.show');
    Route::put('/lessons/{lesson}/register', [RegisterController::class, 'update'])->name('lessons.register.update');
    Route::get('/lessons/{lesson}/report', [LessonReportController::class, 'edit'])->name('lessons.report.edit');
    Route::put('/lessons/{lesson}/report', [LessonReportController::class, 'update'])->name('lessons.report.update');
});

Route::middleware(['auth', 'verified', 'role:guardian'])->group(function () {
    Route::resource('children', ChildController::class)->except(['show', 'destroy']);
    Route::put('children/{child}/password', ChildPasswordController::class)->name('children.password.update');
});

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except(['show'])->withTrashed(['edit']);
    Route::post('users/{user}/restore', RestoreUserController::class)->withTrashed()->name('users.restore');
    Route::put('users/{user}/password', UserPasswordController::class)->name('users.password.update');

    Route::resource('classes', ClassGroupController::class)
        ->except(['show', 'destroy'])
        ->parameters(['classes' => 'classGroup']);
    Route::post('classes/{classGroup}/students', [ClassEnrolmentController::class, 'store'])->name('classes.students.store');
    Route::delete('classes/{classGroup}/students/{student}', [ClassEnrolmentController::class, 'destroy'])->name('classes.students.destroy');
});

if (app()->isLocal()) {
    Route::inertia('/styleguide', 'styleguide')->name('styleguide');
}
