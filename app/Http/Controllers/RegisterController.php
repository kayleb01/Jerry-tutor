<?php

namespace App\Http\Controllers;

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
            'statuses' => [
                ['value' => 'present', 'label' => 'On time'],
                ['value' => 'late', 'label' => 'Late'],
                ['value' => 'absent', 'label' => 'Absent'],
                ['value' => 'excused', 'label' => 'Excused'],
            ],
        ]);
    }

    public function update(UpdateRegisterRequest $request, Lesson $lesson, RecordAttendance $recordAttendance): RedirectResponse
    {
        /** @var array<int|string, array{status: string, arrived_at?: string|null, notes?: string|null}> $attendance */
        $attendance = $request->validated('attendance');
        $people = User::withTrashed()->whereKey(array_keys($attendance))->get()->keyBy('id');

        DB::transaction(function () use ($attendance, $request, $lesson, $recordAttendance, $people): void {
            foreach ($attendance as $userId => $entry) {
                $attended = in_array($entry['status'], ['present', 'late'], true);

                $recordAttendance->handle(
                    $lesson,
                    $people[(int) $userId],
                    $attended ? AttendanceStatus::Present : AttendanceStatus::from($entry['status']),
                    $request->user(),
                    $entry['status'] === 'late' ? $lesson->localTimeToUtc((string) $entry['arrived_at']) : null,
                    $entry['notes'] ?? null,
                );
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Register saved.']);

        return to_route('lessons.register.show', $lesson);
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
        ];
    }
}
