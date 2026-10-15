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
     * The lateness inbox: reports in the chosen status. Each one opens on its own page.
     */
    public function index(Request $request, LatenessReportQuery $query, LatenessInbox $inbox): Response
    {
        $filters = $query->filters($request->query());

        return Inertia::render('admin/lateness/index', [
            'reports' => Inertia::scroll(fn () => $query->filtered($filters)
                ->paginate(25)
                ->withQueryString()
                ->through(fn (LatenessReport $report): array => $inbox->row($report))),
            'counts' => fn () => $inbox->counts($filters),
            'filters' => $this->filterProps($filters),
            'statuses' => collect(LatenessReportQuery::STATUSES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            'classes' => fn () => ClassGroup::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name])
                ->all(),
        ]);
    }

    /**
     * One report on its own page. The list's filters ride along in the query string, so the page
     * knows the report's place in that list for Previous/Next and "Mark as followed up".
     */
    public function show(Request $request, LatenessReport $latenessReport, LatenessReportQuery $query, LatenessInbox $inbox): Response
    {
        $filters = $query->filters($request->query());

        return Inertia::render('admin/lateness/show', [
            'report' => $inbox->detail($latenessReport),
            'filters' => $this->filterProps($filters),
            'neighbours' => $this->neighbours($query->filtered($filters)->pluck('lateness_reports.id')->all(), $latenessReport->id),
        ]);
    }

    /**
     * Save the follow-up. With `advance`, open the next report in the list the admin came from.
     */
    public function update(ReviewLatenessReportRequest $request, LatenessReport $latenessReport, LatenessReportQuery $query): RedirectResponse
    {
        $reviewed = $request->boolean('reviewed');
        $listQuery = $this->referrerFilters();
        // Work out the next report before saving, while this one still holds its place in the list.
        $next = $request->boolean('advance')
            ? $this->neighbours($query->filtered($query->filters($listQuery))->pluck('lateness_reports.id')->all(), $latenessReport->id)['next']
            : null;

        $latenessReport->update([
            'follow_up_notes' => $request->validated('follow_up_notes'),
            'reviewed_by_id' => $reviewed ? ($latenessReport->reviewed_by_id ?? $request->user()->id) : null,
            'reviewed_at' => $reviewed ? ($latenessReport->reviewed_at ?? CarbonImmutable::now()) : null,
        ]);

        if (! $request->boolean('advance')) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $reviewed ? 'Marked as followed up.' : 'Follow-up saved.']);

            return back();
        }

        if ($next === null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Marked as followed up. That was the last one in this list.']);

            return to_route('admin.lateness.index', $listQuery);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Marked as followed up.']);

        return to_route('admin.lateness.show', [$next, ...$listQuery]);
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
     * @param  array{status: string, role: AttendeeRole|null, class: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null, search: string}  $filters
     * @return array{status: string, role: string|null, class: int|null, from: string|null, to: string|null, search: string}
     */
    private function filterProps(array $filters): array
    {
        return [
            'status' => $filters['status'],
            'role' => $filters['role']?->value,
            'class' => $filters['class'],
            'from' => $filters['from']?->toDateString(),
            'to' => $filters['to']?->toDateString(),
            'search' => $filters['search'],
        ];
    }

    /**
     * The list filters from the page the admin came from, and nothing else from that URL.
     *
     * @return array<string, mixed>
     */
    private function referrerFilters(): array
    {
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);

        return Arr::only($query, ['status', 'role', 'class', 'from', 'to', 'search']);
    }

    /**
     * The report's place in a list of ids. A report no longer in the list (e.g. just followed up) has no
     * position, and "next" is the top of the list.
     *
     * @param  array<int, int>  $ids
     * @return array{previous: int|null, next: int|null, position: int|null, total: int}
     */
    private function neighbours(array $ids, int $currentId): array
    {
        $index = array_search($currentId, $ids, true);

        if ($index === false) {
            return ['previous' => null, 'next' => $ids[0] ?? null, 'position' => null, 'total' => count($ids)];
        }

        return [
            'previous' => $ids[$index - 1] ?? null,
            'next' => $ids[$index + 1] ?? null,
            'position' => $index + 1,
            'total' => count($ids),
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
