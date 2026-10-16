<?php

namespace App\Http\Controllers;

use App\Actions\AlertGuardiansOfAbsence;
use App\Actions\RecordAttendance;
use App\Enums\AttendanceStatus;
use App\Http\Requests\UpdateRegisterRequest;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function show(Request $request, Lesson $lesson): Response
    {
        Gate::authorize('takeRegister', $lesson);

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
                'time' => $lesson->localStart()->format('H:i').'–'.$lesson->localEnd()->format('H:i'),
                'location' => $lesson->classGroup->location,
                'status' => $lesson->status->value,
            ],
            'tutor' => $this->row($lesson->tutor, $records->get($lesson->tutor_id)),
            'students' => $students,
            'canEditTutor' => $request->user()->can('recordTutorAttendance', $lesson),
            'canReport' => $request->user()->can('submitReport', $lesson),
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
