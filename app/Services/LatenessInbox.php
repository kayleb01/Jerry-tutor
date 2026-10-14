<?php

namespace App\Services;

use App\Enums\AttendeeRole;
use App\Models\GuardianNotice;
use App\Models\LatenessReport;
use Carbon\CarbonImmutable;

/**
 * The admin's lateness inbox: counts per status, list rows and the detail pane for one report.
 */
class LatenessInbox
{
    /** Days of the person's lateness the detail pane shows as history. */
    public const HISTORY_DAYS = 90;

    /** How many earlier reports the history lists. */
    public const HISTORY_ITEMS = 5;

    /** Lateness within this many days counts towards repeat lateness (3 or more), as on the dashboard. */
    public const REPEAT_DAYS = 30;

    public const REPEAT_THRESHOLD = 3;

    public function __construct(private LatenessReportQuery $query) {}

    /**
     * How many reports each status holds for these filters (their own status is ignored).
     *
     * @param  array{status: string, role: AttendeeRole|null, class: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null, search: string}  $filters
     * @return array<string, int>
     */
    public function counts(array $filters): array
    {
        return collect(array_keys(LatenessReportQuery::STATUSES))
            ->mapWithKeys(fn (string $status): array => [$status => $this->query->filtered([...$filters, 'status' => $status])->count()])
            ->all();
    }

    /**
     * @return array{id: int, name: string, role: string, className: string, date: string, scheduled: string, arrived: string, minutesLate: int, status: string, statusLabel: string}
     */
    public function row(LatenessReport $report): array
    {
        $timezone = config('academy.timezone');
        $followed = $report->reviewed_at !== null;

        return [
            'id' => $report->id,
            'name' => $report->user->name,
            'role' => $report->attendee_role->value,
            'className' => $report->class_name,
            'date' => $this->dateLabel($report->lesson_date),
            'scheduled' => $report->scheduled_start_at->setTimezone($timezone)->format('H:i'),
            'arrived' => $report->arrived_at->setTimezone($timezone)->format('H:i'),
            'minutesLate' => $report->minutes_late,
            'status' => $followed ? 'followed' : 'new',
            'statusLabel' => $followed ? 'Followed up' : 'New',
        ];
    }

    /**
     * Everything the detail pane shows for one report.
     *
     * @return array<string, mixed>
     */
    public function detail(LatenessReport $report): array
    {
        $report->loadMissing(['user.studentProfile', 'reviewedBy', 'guardianNotices.sentBy', 'guardianNotices.acknowledgedBy']);
        $person = $report->user;
        $isStudent = $report->attendee_role === AttendeeRole::Student;
        $guardians = $isStudent ? $person->guardians()->orderBy('name')->pluck('name')->all() : [];
        $today = CarbonImmutable::now(config('academy.timezone'))->startOfDay();
        $timezone = config('academy.timezone');

        $history = LatenessReport::query()
            ->where('user_id', $person->id)
            ->whereDate('lesson_date', '>=', $today->subDays(self::HISTORY_DAYS)->toDateString())
            ->orderByDesc('lesson_date')
            ->orderByDesc('scheduled_start_at')
            ->get(['id', 'class_name', 'lesson_date', 'minutes_late']);

        return [
            ...$this->row($report),
            'roleLabel' => $isStudent ? 'Student' : 'Tutor',
            'yearGroup' => $isStudent ? $person->studentProfile?->year_group->label() : null,
            'reason' => $report->reason,
            'followUpNotes' => $report->follow_up_notes,
            'followedUp' => $report->reviewed_at ? [
                'by' => $report->reviewedBy?->name,
                'at' => $report->reviewed_at->setTimezone($timezone)->format('j M, H:i'),
            ] : null,
            'history' => [
                'items' => $history
                    ->reject(fn (LatenessReport $earlier): bool => $earlier->id === $report->id)
                    ->take(self::HISTORY_ITEMS)
                    ->map(fn (LatenessReport $earlier): array => [
                        'date' => $this->dateLabel($earlier->lesson_date),
                        'className' => $earlier->class_name,
                        'minutesLate' => $earlier->minutes_late,
                    ])
                    ->values()
                    ->all(),
                'total' => $history->count(),
            ],
            'repeatCount' => LatenessReport::query()
                ->where('user_id', $person->id)
                ->whereDate('lesson_date', '>=', $today->subDays(self::REPEAT_DAYS)->toDateString())
                ->count(),
            'parentMessage' => $isStudent ? sprintf(
                '%s arrived %d %s late to %s on %s (the class started at %s).',
                strtok($person->name, ' ') ?: $person->name,
                $report->minutes_late,
                $report->minutes_late === 1 ? 'minute' : 'minutes',
                $report->class_name,
                $report->lesson_date->format('l j F'),
                $report->scheduled_start_at->setTimezone($timezone)->format('H:i'),
            ) : null,
            'canNotifyParent' => $guardians !== [],
            'guardians' => $guardians,
            'notices' => $report->guardianNotices
                ->sortByDesc('id')
                ->values()
                ->map(fn (GuardianNotice $notice): array => [
                    'id' => $notice->id,
                    'note' => $notice->note,
                    'sentBy' => $notice->sentBy?->name,
                    'sentAt' => $notice->created_at->setTimezone($timezone)->format('j M, H:i'),
                    'acknowledgedAt' => $notice->acknowledged_at?->setTimezone($timezone)->format('j M, H:i'),
                    'acknowledgedBy' => $notice->acknowledgedBy?->name,
                ])
                ->all(),
        ];
    }

    private function dateLabel(CarbonImmutable $date): string
    {
        return $date->year === CarbonImmutable::now(config('academy.timezone'))->year
            ? $date->format('D j M')
            : $date->format('D j M Y');
    }
}
