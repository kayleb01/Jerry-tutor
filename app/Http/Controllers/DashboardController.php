<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AdminDashboard;
use App\Services\ReportsDue;
use App\Services\StudentDashboard;
use App\Support\AdminDashboardFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            Role::Tutor => $this->tutorToday($user),
            Role::Student => app(StudentDashboard::class)->for($user, $request->query('week') === 'next'),
            default => [],
        };

        if ($user->hasRole(Role::Tutor, Role::Student)) {
            $props['reportsDue'] = app(ReportsDue::class)->for($user);
        }

        return Inertia::render('dashboard/'.$user->role->value, $props);
    }

    /**
     * Today's lessons (academy day) plus the next few, for the tutor's "Today" screen.
     *
     * @return array{today: array<int, array<string, mixed>>, upcoming: array<int, array<string, mixed>>}
     */
    private function tutorToday(User $tutor): array
    {
        $timezone = config('academy.timezone');
        $startOfDay = CarbonImmutable::now($timezone)->startOfDay();
        $endOfDay = $startOfDay->endOfDay();

        $today = $tutor->lessonsTaught()
            ->with([
                'classGroup' => fn (BelongsTo $query) => $query->withCount('activeStudents'),
                'attendanceRecords' => fn (HasMany $query) => $query->where('user_id', $tutor->id),
                'lessonReports' => fn (HasMany $query) => $query->where('author_id', $tutor->id),
            ])
            ->whereBetween('starts_at', [$startOfDay->utc(), $endOfDay->utc()])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Lesson $lesson) use ($tutor, $timezone): array {
                $own = $lesson->attendanceRecords->first();

                return [
                    'id' => $lesson->id,
                    'className' => $lesson->classGroup->name,
                    'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
                    'location' => $lesson->classGroup->location,
                    'status' => $lesson->status->value,
                    'checkedInAt' => $own?->arrived_at?->setTimezone($timezone)->format('H:i'),
                    'late' => $own?->status === AttendanceStatus::Late,
                    'students' => $lesson->classGroup->active_students_count,
                    'canCheckIn' => $own === null && $tutor->can('checkIn', $lesson),
                    'canReport' => $tutor->can('submitReport', $lesson),
                    'reported' => $lesson->lessonReports->isNotEmpty(),
                ];
            })
            ->all();

        $upcoming = $tutor->lessonsTaught()
            ->with('classGroup')
            ->where('starts_at', '>', $endOfDay->utc())
            ->where('status', '!=', LessonStatus::Cancelled)
            ->orderBy('starts_at')
            ->limit(5)
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'when' => $lesson->localStart()->format('D j M, H:i'),
            ])
            ->all();

        return ['today' => $today, 'upcoming' => $upcoming];
    }
}
