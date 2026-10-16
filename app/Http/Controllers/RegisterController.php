<?php

namespace App\Http\Controllers;

use App\Actions\AlertGuardiansOfAbsence;
use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Enums\LessonStatus;
use App\Http\Requests\UpdateRegisterRequest;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    /**
     * Today's current or next class, else the tutor's most recent past one, else their next upcoming one (read-only until it opens).
     */
    public function next(Request $request): RedirectResponse
    {
        $now = CarbonImmutable::now();
        $timezone = config('academy.timezone');
        $lessons = Lesson::query()
            ->where('tutor_id', $request->user()->id)
            ->where('status', '!=', LessonStatus::Cancelled);

        $today = (clone $lessons)
            ->whereBetween('starts_at', [$now->setTimezone($timezone)->startOfDay()->utc(), $now->setTimezone($timezone)->endOfDay()->utc()])
            ->orderBy('starts_at')
            ->get();

        $lesson = $today->first(fn (Lesson $lesson): bool => $lesson->starts_at <= $now && $lesson->ends_at > $now)
            ?? $today->first(fn (Lesson $lesson): bool => $lesson->starts_at > $now)
            ?? (clone $lessons)->where('starts_at', '<=', $now->utc())->latest('starts_at')->first()
            ?? (clone $lessons)->where('starts_at', '>', $now->utc())->orderBy('starts_at')->first();

        if ($lesson === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'You have no classes to take a register for.']);

            return to_route('dashboard');
        }

        return to_route('lessons.register.show', $lesson);
    }

    public function show(Request $request, Lesson $lesson): Response
    {
        Gate::authorize('viewRegister', $lesson);

        $user = $request->user();
        $canEdit = $user->can('takeRegister', $lesson);

        $lesson->load(['classGroup', 'tutor', 'attendanceRecords']);
        $records = $lesson->attendanceRecords->keyBy('user_id');

        $studentIds = $lesson->classGroup->activeStudents()->pluck('users.id')
            ->merge($records->keys())
            ->reject(fn (int $id): bool => $id === $lesson->tutor_id)
            ->unique();

        $students = User::withTrashed()
            ->whereKey($studentIds)
            ->with('studentProfile')
            ->withExists('guardians')
            ->orderBy('name')
            ->get()
            ->map(fn (User $student): array => $this->row($student, $records->get($student->id)))
            ->values();

        return Inertia::render('lessons/register', [
            'lesson' => [
                'id' => $lesson->id,
                'className' => $lesson->classGroup->name,
                'date' => $lesson->localStart()->format('l j F'),
                'dateLong' => $lesson->localStart()->format('l j F'),
                'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
                'location' => $lesson->classGroup->location,
                'status' => $lesson->status->value,
            ],
            'tutor' => $this->row($lesson->tutor, $records->get($lesson->tutor_id)),
            'students' => $students,
            'canEdit' => $canEdit,
            'editingNote' => $canEdit ? null : $this->editingNote($lesson),
            'checkIn' => $lesson->tutor_id === $user->id ? $this->checkIn($user, $lesson, $records->get($lesson->tutor_id)) : null,
            'canEditTutor' => $user->can('recordTutorAttendance', $lesson),
            'canReport' => $user->can('submitReport', $lesson),
            'statuses' => [
                ['value' => 'present', 'label' => 'On time'],
                ['value' => 'late', 'label' => 'Late'],
                ['value' => 'absent', 'label' => 'Absent'],
            ],
            'graceMinutes' => (int) config('academy.lateness_grace_minutes'),
        ]);
    }

    public function update(UpdateRegisterRequest $request, Lesson $lesson, RecordAttendance $recordAttendance, AlertGuardiansOfAbsence $alertGuardians): RedirectResponse
    {
        /** @var array<int|string, array{status: string, arrived_at?: string|null, notes?: string|null}> $attendance */
        $attendance = $request->validated('attendance');
        $people = User::withTrashed()->whereKey(array_keys($attendance))->get()->keyBy('id');
        $existing = $lesson->attendanceRecords()->get()->keyBy('user_id');

        DB::transaction(function () use ($attendance, $request, $lesson, $recordAttendance, $alertGuardians, $people, $existing): void {
            foreach ($attendance as $userId => $entry) {
                $record = $existing->get((int) $userId);

                if ($this->isUnchanged($lesson, $record, $entry)) {
                    continue;
                }

                $attended = in_array($entry['status'], ['present', 'late'], true);
                $arrivedAt = match (true) {
                    $entry['status'] === 'late' => $lesson->localTimeToUtc((string) $entry['arrived_at']),
                    $entry['status'] === 'present' && filled($entry['arrived_at'] ?? null) => $lesson->localTimeToUtc((string) $entry['arrived_at']),
                    // Keep a real on-time arrival (e.g. the tutor's 08:58 check-in) instead of resetting it to the start.
                    $entry['status'] === 'present' && $record?->status === AttendanceStatus::Present => $record->arrived_at,
                    default => null,
                };

                $record = $recordAttendance->handle(
                    $lesson,
                    $people[(int) $userId],
                    $attended ? AttendanceStatus::Present : AttendanceStatus::from($entry['status']),
                    $request->user(),
                    $arrivedAt,
                    $entry['notes'] ?? null,
                );

                $alertGuardians->handle($record, $request->user());
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Register saved.']);

        return to_route('lessons.register.show', $lesson);
    }

    private function editingNote(Lesson $lesson): string
    {
        $opens = $lesson->localStart()->subMinutes(30);

        if (CarbonImmutable::now()->lessThan($lesson->starts_at->subMinutes(30))) {
            return $opens->isToday()
                ? 'The register opens at '.$opens->format('H:i').'.'
                : 'The register opens on '.$opens->format('D j M \a\t H:i').'.';
        }

        return 'This register closed on '.$lesson->localEnd()->addDay()->format('D j M').'.';
    }

    /**
     * @return array{at: string|null, minutesLate: int|null, canCheckIn: bool}
     */
    private function checkIn(User $user, Lesson $lesson, ?AttendanceRecord $record): array
    {
        $checkedIn = $record?->arrived_at !== null;

        return [
            'at' => $checkedIn ? $record->arrived_at->setTimezone(config('academy.timezone'))->format('H:i') : null,
            'minutesLate' => $checkedIn ? $record->minutes_late : null,
            'canCheckIn' => ! $checkedIn && $record === null && $user->can('checkIn', $lesson),
        ];
    }

    /**
     * A row the form re-submitted exactly as stored is left alone, so its arrival time and recorder are kept.
     *
     * @param  array{status: string, arrived_at?: string|null, notes?: string|null}  $entry
     */
    private function isUnchanged(Lesson $lesson, ?AttendanceRecord $record, array $entry): bool
    {
        if ($record === null || ($record->notes ?? '') !== ($entry['notes'] ?? '')) {
            return false;
        }

        return match ($entry['status']) {
            'present' => $record->status === AttendanceStatus::Present
                && (blank($entry['arrived_at'] ?? null) || $record->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i') === $entry['arrived_at']),
            'late' => $record->status === AttendanceStatus::Late
                && $record->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i') === ($entry['arrived_at'] ?? null),
            default => $record->status->value === $entry['status'],
        };
    }

    /**
     * @return array{id: int, name: string, yearGroup: string|null, status: string|null, arrivedAt: string|null, minutesLate: int|null, notes: string|null}
     */
    private function row(User $person, ?AttendanceRecord $record): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'yearGroup' => $person->studentProfile?->year_group->label(),
            'status' => $record?->status->value,
            'arrivedAt' => $record?->arrived_at?->setTimezone(config('academy.timezone'))->format('H:i'),
            'minutesLate' => $record?->minutes_late,
            'notes' => $record?->notes,
            // Whether marking them absent would alert a parent.
            'hasParent' => (bool) ($person->guardians_exists ?? false),
        ];
    }
}
