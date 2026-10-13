<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewLatenessReportRequest;
use App\Models\ClassGroup;
use App\Models\LatenessReport;
use App\Services\LatenessReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LatenessReportController extends Controller
{
    public function index(Request $request, LatenessReportQuery $query): Response
    {
        $filters = $query->filters($request->query());

        return Inertia::render('admin/lateness/index', [
            'reports' => $query->filtered($filters)
                ->paginate(25)
                ->withQueryString()
                ->through(fn (LatenessReport $report): array => $this->row($report)),
            'filters' => [
                'role' => $filters['role']?->value,
                'class' => $filters['class'],
                'from' => $filters['from']?->toDateString(),
                'to' => $filters['to']?->toDateString(),
                'search' => $filters['search'],
            ],
            'classes' => ClassGroup::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])
                ->all(),
            'repeat' => $query->repeatOffenders(),
        ]);
    }

    public function update(ReviewLatenessReportRequest $request, LatenessReport $latenessReport): RedirectResponse
    {
        $reviewed = $request->boolean('reviewed');

        $latenessReport->update([
            'follow_up_notes' => $request->validated('follow_up_notes'),
            'reviewed_by_id' => $reviewed ? ($latenessReport->reviewed_by_id ?? $request->user()->id) : null,
            'reviewed_at' => $reviewed ? ($latenessReport->reviewed_at ?? CarbonImmutable::now()) : null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Follow-up saved.']);

        return back();
    }

    public function export(Request $request, LatenessReportQuery $query): StreamedResponse
    {
        $filters = $query->filters($request->query());
        $timezone = config('academy.timezone');

        return response()->streamDownload(function () use ($query, $filters, $timezone): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Name', 'Role', 'Class', "Scheduled start ({$timezone})", "Arrived ({$timezone})", 'Minutes late', 'Reviewed by', 'Reviewed at', 'Follow-up notes'], escape: '');

            $query->filtered($filters)->lazy(500)->each(function (LatenessReport $report) use ($out, $timezone): void {
                fputcsv($out, array_map($this->safeCell(...), [
                    $report->lesson_date->toDateString(),
                    $report->user->name,
                    $report->attendee_role->value,
                    $report->class_name,
                    $report->scheduled_start_at->setTimezone($timezone)->format('H:i'),
                    $report->arrived_at->setTimezone($timezone)->format('H:i'),
                    (string) $report->minutes_late,
                    $report->reviewedBy?->name ?? '',
                    $report->reviewed_at?->setTimezone($timezone)->format('Y-m-d H:i') ?? '',
                    $report->follow_up_notes ?? '',
                ]), escape: '');
            });

            fclose($out);
        }, 'lateness-reports-'.CarbonImmutable::now($timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LatenessReport $report): array
    {
        $timezone = config('academy.timezone');

        return [
            'id' => $report->id,
            'date' => $report->lesson_date->format('D j M Y'),
            'name' => $report->user->name,
            'role' => $report->attendee_role->value,
            'className' => $report->class_name,
            'scheduled' => $report->scheduled_start_at->setTimezone($timezone)->format('H:i'),
            'arrived' => $report->arrived_at->setTimezone($timezone)->format('H:i'),
            'minutesLate' => $report->minutes_late,
            'followUpNotes' => $report->follow_up_notes,
            'reviewedBy' => $report->reviewedBy?->name,
            'reviewedAt' => $report->reviewed_at?->setTimezone($timezone)->format('j M, H:i'),
        ];
    }

    /**
     * Stop spreadsheet apps from treating a cell as a formula.
     */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
