<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\GuardianNotice;
use App\Models\RaisedIssue;
use App\Models\User;
use App\Services\AdminDashboard;
use App\Services\GuardianDashboard;
use App\Services\ReportsDue;
use App\Services\StudentDashboard;
use App\Services\TutorDashboard;
use App\Support\AdminDashboardFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the signed-in user the dashboard for their role.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $props = match ($user->role) {
            Role::Admin => app(AdminDashboard::class)->for($user, AdminDashboardFilters::fromQuery($request->query())),
            Role::Tutor => [...app(TutorDashboard::class)->for($user), 'fromAcademy' => $this->fromAcademy($user)],
            Role::Student => app(StudentDashboard::class)->for($user, $request->query('week') === 'next'),
            Role::Guardian => [
                'fromAcademy' => $this->noticesForGuardian($user),
                'children' => app(GuardianDashboard::class)->childrenOf($user),
                'paymentsAvailable' => app(GuardianDashboard::class)->paymentsAvailable(),
            ],
            default => [],
        };

        if ($user->hasRole(Role::Student)) {
            $props['reportsDue'] = app(ReportsDue::class)->for($user);
        }

        return Inertia::render('dashboard/'.$user->role->value, $props);
    }

    /**
     * Notes the academy raised about this tutor's classes that they haven't acknowledged yet.
     *
     * @return array<int, array{id: int, className: string, when: string, note: string, raisedBy: string|null}>
     */
    private function fromAcademy(User $tutor): array
    {
        return RaisedIssue::query()
            ->where('tutor_id', $tutor->id)
            ->whereNull('acknowledged_at')
            ->with(['lesson.classGroup', 'lesson.slot.subject', 'raisedBy'])
            ->latest('id')
            ->get()
            ->map(fn (RaisedIssue $issue): array => [
                'id' => $issue->id,
                'className' => $issue->lesson->title(),
                'when' => $issue->lesson->localStart()->format('D j M'),
                'note' => $issue->note,
                'raisedBy' => $issue->raisedBy?->name,
            ])
            ->all();
    }

    /**
     * Unread notes from the academy about the guardian's children, newest first.
     *
     * @return array<int, array{id: int, childName: string, when: string, note: string, sentBy: string|null}>
     */
    private function noticesForGuardian(User $guardian): array
    {
        return GuardianNotice::query()
            ->whereIn('student_id', $guardian->children()->select('users.id'))
            ->whereNull('acknowledged_at')
            ->with(['student', 'sentBy'])
            ->latest('id')
            ->get()
            ->map(fn (GuardianNotice $notice): array => [
                'id' => $notice->id,
                'childName' => $notice->student->name,
                'when' => $notice->created_at->setTimezone(config('academy.timezone'))->format('D j M'),
                'note' => $notice->note,
                'sentBy' => $notice->sentBy?->name,
            ])
            ->all();
    }
}
