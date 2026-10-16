<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\KeyStage;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\ClassGroup;
use App\Services\AttendanceLog;
use App\Support\AdminDashboardFilters;
use App\Support\AttendanceFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    /**
     * Attendance and punctuality at a glance: the period's totals and every record, for students and tutors.
     */
    public function index(Request $request, AttendanceLog $log): Response
    {
        $filters = AttendanceFilters::fromQuery($request->query());

        return Inertia::render('admin/attendance/index', [
            'filters' => $filters->toProps(),
            'options' => [
                'periods' => collect(AdminDashboardFilters::PERIODS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'stages' => KeyStage::options(),
                'classes' => ClassGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])->all(),
            ],
            'summary' => $log->summary($filters),
            'records' => $log->records($filters)
                ->paginate(10)
                ->withQueryString()
                ->through(fn (AttendanceRecord $record): array => $log->row($record)),
            'todayLessons' => $log->todayLessons(),
        ]);
    }

    /**
     * The filtered records as CSV, times in academy time.
     */
    public function export(Request $request, AttendanceLog $log): StreamedResponse
    {
        $filters = AttendanceFilters::fromQuery($request->query());
        $timezone = config('academy.timezone');

        return response()->streamDownload(function () use ($log, $filters, $timezone): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Name', 'Role', 'Class', "Scheduled start ({$timezone})", "Arrived ({$timezone})", 'Attendance', 'Minutes late', 'Notes'], escape: '');

            $log->records($filters)->lazy(500)->each(function (AttendanceRecord $record) use ($out, $timezone): void {
                fputcsv($out, array_map($this->safeCell(...), [
                    $record->lesson->localStart()->toDateString(),
                    $record->user?->name ?? 'Deleted user',
                    $record->attendee_role->value,
                    $record->lesson->classGroup->name,
                    $record->lesson->localStart()->format('H:i'),
                    $record->arrived_at?->setTimezone($timezone)->format('H:i') ?? '',
                    $record->status->label(),
                    $record->status === AttendanceStatus::Late ? (string) $record->minutes_late : '',
                    $record->notes ?? '',
                ]), escape: '');
            });

            fclose($out);
        }, 'attendance-'.CarbonImmutable::now($timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Stop spreadsheet apps from treating a cell as a formula.
     */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
