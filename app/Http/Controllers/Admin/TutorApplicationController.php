<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ApproveTutorApplication;
use App\Enums\ApprovalStatus;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveTutorRequest;
use App\Models\ClassSlot;
use App\Models\TutorClassRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TutorApplicationController extends Controller
{
    /**
     * Verified applications, oldest first: pending by default, or the rejected ones to reconsider.
     */
    public function index(Request $request): Response
    {
        $status = $request->query('status') === 'rejected' ? ApprovalStatus::Rejected : ApprovalStatus::Pending;

        $applications = User::query()
            ->tutorApplications($status)
            ->with(['requestedClasses.classGroup', 'requestedClasses.subject'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $tutor): array => [
                'id' => $tutor->id,
                'name' => $tutor->name,
                'email' => $tutor->email,
                'phone' => $tutor->phone,
                'appliedOn' => $this->appliedOn($tutor),
                'classes' => $tutor->requestedClasses->map(fn (TutorClassRequest $classRequest): string => $classRequest->label())->all(),
            ]);

        return Inertia::render('admin/tutor-applications/index', [
            'applications' => $applications,
            'filters' => ['status' => $status->value],
            'counts' => [
                'pending' => User::query()->tutorApplications(ApprovalStatus::Pending)->count(),
                'rejected' => User::query()->tutorApplications(ApprovalStatus::Rejected)->count(),
            ],
        ]);
    }

    /**
     * The applicant and, for each class and subject they asked for, that subject's slots in the class.
     */
    public function show(User $tutor): Response
    {
        abort_unless($tutor->hasReviewableApplication(), 404);

        $requests = $tutor->requestedClasses()->with(['classGroup', 'subject'])->orderBy('id')->get();
        $slots = ClassSlot::query()
            ->whereIn('class_group_id', $requests->pluck('class_group_id'))
            ->whereIn('subject_id', $requests->pluck('subject_id'))
            ->with('tutor')
            ->orderByRaw('weekday is null')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/tutor-applications/show', [
            'applicant' => [
                'id' => $tutor->id,
                'name' => $tutor->name,
                'email' => $tutor->email,
                'phone' => $tutor->phone,
                'appliedOn' => $this->appliedOn($tutor),
                'status' => $tutor->approval_status->value,
            ],
            'rows' => $requests->map(fn (TutorClassRequest $classRequest): array => [
                'requestId' => $classRequest->id,
                'label' => $classRequest->label(),
                'slots' => $slots
                    ->where('class_group_id', $classRequest->class_group_id)
                    ->where('subject_id', $classRequest->subject_id)
                    ->map(fn (ClassSlot $slot): array => [
                        'slotId' => $slot->id,
                        'timeLabel' => $slot->timeLabel(),
                        'currentTutor' => $slot->tutor?->name,
                    ])
                    ->values()
                    ->all(),
            ])->all(),
            'weekdays' => Weekday::options(),
        ]);
    }

    public function approve(ApproveTutorRequest $request, User $tutor, ApproveTutorApplication $approve): RedirectResponse
    {
        $emailSent = $approve->handle($tutor, $request->validated('decisions'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $emailSent
            ? "{$tutor->name} is approved."
            : "{$tutor->name} is approved, but the email couldn't be sent."]);

        return to_route('admin.tutor-applications.index');
    }

    public function reject(User $tutor): RedirectResponse
    {
        abort_unless($tutor->hasReviewableApplication(), 404);

        $tutor->forceFill(['approval_status' => ApprovalStatus::Rejected])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tutor->name}'s application was rejected."]);

        return to_route('admin.tutor-applications.index');
    }

    private function appliedOn(User $tutor): string
    {
        return $tutor->created_at->setTimezone(config('academy.timezone'))->format('j M Y');
    }
}
