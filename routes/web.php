<?php

use App\Http\Controllers\Admin\ClassEnrolmentController;
use App\Http\Controllers\Admin\ClassGroupController;
use App\Http\Controllers\Admin\LatenessReportController;
use App\Http\Controllers\Admin\LessonReportController as AdminLessonReportController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\QuestionImportController;
use App\Http\Controllers\Admin\QuestionStatusController;
use App\Http\Controllers\Admin\RestoreUserController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\ChildAttendanceController;
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
    Route::get('children/{child}/attendance', ChildAttendanceController::class)->name('children.attendance');
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

    Route::get('lesson-reports', [AdminLessonReportController::class, 'index'])->name('lesson-reports.index');

    Route::get('lateness/export', [LatenessReportController::class, 'export'])->name('lateness.export');
    Route::get('lateness', [LatenessReportController::class, 'index'])->name('lateness.index');
    Route::put('lateness/{latenessReport}', [LatenessReportController::class, 'update'])->name('lateness.update');

    Route::get('questions/import/template', [QuestionImportController::class, 'template'])->name('questions.import.template');
    Route::get('questions/import', [QuestionImportController::class, 'create'])->name('questions.import');
    Route::post('questions/import', [QuestionImportController::class, 'store'])->name('questions.import.store');
    Route::put('questions/bulk-status', [QuestionStatusController::class, 'bulk'])->name('questions.bulk-status');
    Route::put('questions/{question}/status', [QuestionStatusController::class, 'update'])->name('questions.status.update');
    Route::resource('questions', QuestionController::class)->except(['show']);
});

if (app()->isLocal()) {
    Route::inertia('/styleguide', 'styleguide')->name('styleguide');
}
