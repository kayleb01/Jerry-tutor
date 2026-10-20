<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\BillingSettingsController;
use App\Http\Controllers\Admin\ClassEnrolmentController;
use App\Http\Controllers\Admin\ClassGroupController;
use App\Http\Controllers\Admin\ClassSlotController;
use App\Http\Controllers\Admin\LatenessReportController;
use App\Http\Controllers\Admin\LessonReportController as AdminLessonReportController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\QuestionImportController;
use App\Http\Controllers\Admin\QuestionStatusController;
use App\Http\Controllers\Admin\RestoreUserController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\SubscriptionActionsController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\BillingCallbackController;
use App\Http\Controllers\BillingCheckoutController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CancelSubscriptionController;
use App\Http\Controllers\ChildAttendanceController;
use App\Http\Controllers\ChildController;
use App\Http\Controllers\ChildPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuardianNoticeController;
use App\Http\Controllers\LessonReportController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\QuizPlayerController;
use App\Http\Controllers\RaisedIssueController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\TutorApplicationStatusController;
use App\Http\Controllers\TutorCheckInController;
use App\Http\Controllers\UpdateCardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::get('tutors/application', TutorApplicationStatusController::class)
    ->middleware(['auth', 'verified'])
    ->name('tutors.application');

Route::middleware(['auth', 'verified', 'approved.tutor'])->group(function () {
    Route::put('raised-issues/{raisedIssue}/acknowledge', [RaisedIssueController::class, 'acknowledge'])
        ->middleware('role:tutor')
        ->name('raised-issues.acknowledge');
    Route::put('guardian-notices/{guardianNotice}/acknowledge', [GuardianNoticeController::class, 'acknowledge'])
        ->middleware('role:guardian')
        ->name('guardian-notices.acknowledge');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::post('/lessons/{lesson}/check-in', TutorCheckInController::class)->name('lessons.check-in');
    Route::get('/take-register', [RegisterController::class, 'next'])->middleware('role:tutor')->name('registers.next');
    Route::get('/lessons/{lesson}/register', [RegisterController::class, 'show'])->name('lessons.register.show');
    Route::put('/lessons/{lesson}/register', [RegisterController::class, 'update'])->name('lessons.register.update');
    Route::get('/report', [LessonReportController::class, 'next'])->middleware('role:tutor')->name('reports.next');
    Route::get('/lessons/{lesson}/report', [LessonReportController::class, 'edit'])->name('lessons.report.edit');
    Route::put('/lessons/{lesson}/report', [LessonReportController::class, 'update'])->name('lessons.report.update');
});

Route::post('webhooks/{gateway}', PaymentWebhookController::class)
    ->whereIn('gateway', ['paystack', 'flutterwave'])
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

Route::middleware(['auth', 'verified', 'approved.tutor', 'role:student', 'quiz.access'])->group(function () {
    Route::get('quizzes', [QuizController::class, 'index'])->name('quizzes.index');
    Route::post('quizzes/{quiz:slug}/attempts', [QuizAttemptController::class, 'store'])->name('quizzes.attempts.store');
    Route::get('quiz-attempts/{attempt}', [QuizAttemptController::class, 'show'])->name('quiz-attempts.show');
    Route::post('quiz-attempts/{attempt}/submit', [QuizAttemptController::class, 'submit'])->name('quiz-attempts.submit');
    Route::get('quiz-attempts/{attempt}/questions/{position}', [QuizPlayerController::class, 'show'])->whereNumber('position')->name('quiz-attempts.questions.show');
    Route::put('quiz-attempts/{attempt}/questions/{position}', [QuizPlayerController::class, 'update'])->whereNumber('position')->name('quiz-attempts.questions.update');
});

Route::middleware(['auth', 'verified', 'approved.tutor', 'role:guardian,student'])->prefix('billing')->name('billing.')->group(function () {
    Route::get('/', [BillingController::class, 'index'])->name('index');
    Route::post('checkout', [BillingCheckoutController::class, 'store'])->name('checkout');
    Route::get('callback/{gateway}', BillingCallbackController::class)->whereIn('gateway', ['paystack', 'flutterwave'])->name('callback');
    Route::post('subscriptions/{subscription}/cancel', CancelSubscriptionController::class)->name('subscriptions.cancel');
    Route::get('subscriptions/{subscription}/card', UpdateCardController::class)->name('subscriptions.card');
});

Route::middleware(['auth', 'verified', 'approved.tutor', 'role:guardian'])->group(function () {
    Route::resource('children', ChildController::class)->except(['show', 'destroy']);
    Route::put('children/{child}/password', ChildPasswordController::class)->name('children.password.update');
    Route::get('children/{child}/attendance', ChildAttendanceController::class)->name('children.attendance');
});

Route::middleware(['auth', 'verified', 'approved.tutor', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except(['show'])->withTrashed(['edit']);
    Route::post('users/{user}/restore', RestoreUserController::class)->withTrashed()->name('users.restore');
    Route::put('users/{user}/password', UserPasswordController::class)->name('users.password.update');

    Route::resource('classes', ClassGroupController::class)
        ->except(['show', 'destroy'])
        ->parameters(['classes' => 'classGroup']);
    Route::scopeBindings()->group(function () {
        Route::post('classes/{classGroup}/slots', [ClassSlotController::class, 'store'])->name('classes.slots.store');
        Route::put('classes/{classGroup}/slots/{slot}', [ClassSlotController::class, 'update'])->name('classes.slots.update');
        Route::delete('classes/{classGroup}/slots/{slot}', [ClassSlotController::class, 'destroy'])->name('classes.slots.destroy');
    });
    Route::post('classes/{classGroup}/students', [ClassEnrolmentController::class, 'store'])->name('classes.students.store');
    Route::delete('classes/{classGroup}/students/{student}', [ClassEnrolmentController::class, 'destroy'])->name('classes.students.destroy');

    Route::get('subjects', [SubjectController::class, 'index'])->name('subjects.index');
    Route::post('subjects', [SubjectController::class, 'store'])->name('subjects.store');
    Route::put('subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');

    Route::get('payments/export', [AdminPaymentController::class, 'export'])->name('payments.export');
    Route::get('payments', [AdminPaymentController::class, 'index'])->name('payments.index');
    Route::get('subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions/{subscription}/remind', [SubscriptionActionsController::class, 'remind'])->name('subscriptions.remind');
    Route::post('subscriptions/{subscription}/pause', [SubscriptionActionsController::class, 'pause'])->name('subscriptions.pause');
    Route::post('subscriptions/{subscription}/retry', [SubscriptionActionsController::class, 'retry'])->name('subscriptions.retry');
    Route::get('billing', [BillingSettingsController::class, 'index'])->name('billing.index');
    Route::put('billing/plans/{plan}', [BillingSettingsController::class, 'update'])->name('billing.plans.update');

    Route::get('lesson-reports', [AdminLessonReportController::class, 'index'])->name('lesson-reports.index');
    Route::put('lesson-reports/{lesson}/review', [AdminLessonReportController::class, 'review'])->name('lesson-reports.review');
    Route::post('lesson-reports/{lesson}/raise', [AdminLessonReportController::class, 'raise'])->name('lesson-reports.raise');

    Route::get('attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');

    Route::get('lateness/export', [LatenessReportController::class, 'export'])->name('lateness.export');
    Route::get('lateness', [LatenessReportController::class, 'index'])->name('lateness.index');
    Route::get('lateness/{latenessReport}', [LatenessReportController::class, 'show'])->name('lateness.show');
    Route::put('lateness/{latenessReport}', [LatenessReportController::class, 'update'])->name('lateness.update');
    Route::post('lateness/{latenessReport}/notify-parent', [LatenessReportController::class, 'notifyParent'])->name('lateness.notify-parent');

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
