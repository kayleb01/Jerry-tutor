<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceStats;
use Inertia\Inertia;
use Inertia\Response;

class ChildAttendanceController extends Controller
{
    /**
     * A guardian's view of one child's attendance and punctuality.
     */
    public function __invoke(User $child, AttendanceStats $stats): Response
    {
        $records = AttendanceRecord::query()
            ->where('user_id', $child->id)
            ->join('lessons', 'lessons.id', '=', 'attendance_records.lesson_id')
            ->select('attendance_records.*')
            ->with(['lesson.classGroup', 'lesson.slot.subject'])
            ->orderByDesc('lessons.starts_at')
            ->limit(30)
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id' => $record->id,
                'date' => $record->lesson->localStart()->format('D j M'),
                'className' => $record->lesson->title(),
                'time' => $record->lesson->localStart()->format('H:i').'–'.$record->lesson->localEnd()->format('H:i'),
                'status' => $record->status->value,
                'minutesLate' => $record->minutes_late,
            ]);

        return Inertia::render('children/attendance', [
            'child' => [
                'id' => $child->id,
                'name' => $child->name,
                'yearGroup' => $child->studentProfile?->year_group->label(),
            ],
            'stats' => $stats->forUser($child),
            'records' => $records,
        ]);
    }
}
