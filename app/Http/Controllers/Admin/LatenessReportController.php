<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendeeRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewLatenessReportRequest;
use App\Models\ClassGroup;
use App\Models\GuardianNotice;
use App\Models\LatenessReport;
use App\Services\LatenessInbox;
use App\Services\LatenessReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LatenessReportController extends Controller
{
    /**
     * The lateness inbox: reports in the chosen status on the left, one report's detail on the right.
     */
    public function index(Request $request, LatenessReportQuery $query, LatenessInbox $inbox): Response
    {
        $filters = $query->filters($request->query());
        $selectedId = filter_var($request->query('report'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        return Inertia::render('admin/lateness/index', [
            'reports' => Inertia::scroll(fn () => $query->filtered($filters)
                ->paginate(25)
                ->withQueryString()
                ->through(fn (LatenessReport $report): array => $inbox->row($report))),
            'counts' => fn () => $inbox->counts($filters),
            'filters' => [
                'status' => $filters['status'],
                'role' => $filters['role']?->value,
                'class' => $filters['class'],
                'from' => $filters['from']?->toDateString(),
                'to' => $filters['to']?->toDateString(),
                'search' => $filters['search'],
                'report' => $selectedId,
            ],
            'statuses' => collect(LatenessReportQuery::STATUSES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            'classes' => fn () => ClassGroup::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])
                ->all(),
            'selected' => function () use ($query, $filters, $inbox, $selectedId): ?array {
                $report = $selectedId ? LatenessReport::find($selectedId) : $query->filtered($filters)->first();

                return $report ? $inbox->detail($report) : null;
            },
        ]);
    }

    /**
     * Save the follow-up. With `next`, open that report afterwards (the "Mark as followed up" flow).
     */
    public function update(ReviewLatenessReportRequest $request, LatenessReport $latenessReport): RedirectResponse
    {
        $reviewed = $request->boolean('reviewed');

        $latenessReport->update([
            'follow_up_notes' => $request->validated('follow_up_notes'),
            'reviewed_by_id' => $reviewed ? ($latenessReport->reviewed_by_id ?? $request->user()->id) : null,
            'reviewed_at' => $reviewed ? ($latenessReport->reviewed_at ?? CarbonImmutable::now()) : null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $reviewed ? 'Marked as followed up.' : 'Follow-up saved.']);

        $next = $request->validated('next');

        return $next ? redirect($this->inboxUrl((int) $next)) : back();
    }

    /**
     * Send the student's guardians a note they'll see on their dashboard. Sending it counts as the follow-up.
     */
    public function notifyParent(Request $request, LatenessReport $latenessReport): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $student = $latenessReport->user;
        $guardians = $latenessReport->attendee_role === AttendeeRole::Student
            ? $student->guardians()->orderBy('name')->pluck('name')
            : collect();

        abort_if($guardians->isEmpty(), 422, 'Only students with a linked parent or guardian can be notified.');

        DB::transaction(function () use ($latenessReport, $request, $validated): void {
            GuardianNotice::create([
                'student_id' => $latenessReport->user_id,
                'lateness_report_id' => $latenessReport->id,
                'sent_by_id' => $request->user()->id,
                'note' => trim($validated['note']),
            ]);

            if ($latenessReport->reviewed_at === null) {
                $latenessReport->update(['reviewed_at' => CarbonImmutable::now(), 'reviewed_by_id' => $request->user()->id]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to '.$guardians->join(', ', ' and ').'.']);

        return redirect($this->inboxUrl($latenessReport->id));
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
     * The inbox with the filters the admin came from (and nothing else from that URL), opened on a report.
     */
    private function inboxUrl(int $reportId): string
    {
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);

        return route('admin.lateness.index', [...Arr::only($query, ['status', 'role', 'class', 'from', 'to', 'search']), 'report' => $reportId]);
    }

    /**
     * Stop spreadsheet apps from treating a cell as a formula.
     */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
