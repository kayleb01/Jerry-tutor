<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Enums\LessonStatus;
use App\Enums\RecommendedAction;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\LessonReport;
use App\Models\RaisedIssue;
use App\Policies\LessonPolicy;
use App\Support\AcademyDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The admin's review inbox for end-of-class reports: one row per lesson, sorted into queues.
 * A lesson needs review while any of its reports arrived after the last review.
 */
class ClassReportInbox
{
    /** @var array<string, string> queue key => tab label, in tab order */
    public const QUEUES = [
        'review' => 'Needs review',
        'raised' => 'Raised with tutor',
        'missing' => 'Awaiting tutor report',
        'reviewed' => 'Reviewed',
        'all' => 'All',
    ];

    public const DEFAULT_QUEUE = 'review';

    /**
     * Read the inbox filters from a query string, ignoring anything unknown or malformed.
     *
     * @param  array<string, mixed>  $input
     * @return array{queue: string, search: string, class: int|null, tutor: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null}
     */
    public function filters(array $input): array
    {
        $queue = $input['queue'] ?? null;

        return [
            'queue' => is_string($queue) && array_key_exists($queue, self::QUEUES) ? $queue : self::DEFAULT_QUEUE,
            'search' => is_string($input['search'] ?? null) ? Str::lower(trim($input['search'])) : '',
            'class' => $this->positiveInt($input['class'] ?? null),
            'tutor' => $this->positiveInt($input['tutor'] ?? null),
            'from' => AcademyDate::fromQuery($input['from'] ?? null),
            'to' => AcademyDate::fromQuery($input['to'] ?? null),
        ];
    }

    /**
     * Lessons in one queue (the filters' queue unless another is given), unordered.
     *
     * @param  array{queue: string, search: string, class: int|null, tutor: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null}  $filters
     * @return Builder<Lesson>
     */
    public function query(array $filters, ?string $queue = null, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();
        $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
        $nameMatches = fn (Builder $query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$search]);

        $query = Lesson::query()
            ->where('lessons.status', '!=', LessonStatus::Cancelled)
            ->where('lessons.starts_at', '<=', $now)
            ->when($filters['class'], fn (Builder $query, int $class) => $query->where('lessons.class_group_id', $class))
            ->when($filters['tutor'], fn (Builder $query, int $tutor) => $query->where('lessons.tutor_id', $tutor))
            ->when($filters['from'], fn (Builder $query, CarbonImmutable $from) => $query->where('lessons.starts_at', '>=', $from->startOfDay()->utc()))
            ->when($filters['to'], fn (Builder $query, CarbonImmutable $to) => $query->where('lessons.starts_at', '<=', $to->endOfDay()->utc()))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('classGroup', $nameMatches)
                ->orWhereHas('tutor', $nameMatches)
                ->orWhereHas('lessonReports.author', $nameMatches)));

        return match ($queue ?? $filters['queue']) {
            'review' => $query->whereHas('lessonReports', $this->unreviewed(...)),
            'raised' => $query->whereHas('raisedIssues', fn (Builder $issues) => $issues->whereNull('acknowledged_at')),
            'missing' => $query
                ->where('lessons.ends_at', '<', $now)
                ->whereHas('attendanceRecords', $this->attended(...))
                ->whereDoesntHave('lessonReports', fn (Builder $reports) => $reports->where('author_role', AttendeeRole::Tutor)),
            'reviewed' => $query
                ->whereNotNull('lessons.reports_reviewed_at')
                ->whereDoesntHave('lessonReports', $this->unreviewed(...)),
            default => $query->where(fn (Builder $query) => $query->whereHas('lessonReports')->orWhereHas('attendanceRecords', $this->attended(...))),
        };
    }

    /**
     * How many lessons are in each queue for these filters (the filters' own queue is ignored).
     *
     * @param  array{queue: string, search: string, class: int|null, tutor: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null}  $filters
     * @return array<string, int>
     */
    public function counts(array $filters): array
    {
        $now = CarbonImmutable::now();

        return collect(array_keys(self::QUEUES))
            ->mapWithKeys(fn (string $queue): array => [$queue => $this->query($filters, $queue, $now)->count()])
            ->all();
    }

    /**
     * One page of list rows, newest lesson first.
     *
     * @param  array{queue: string, search: string, class: int|null, tutor: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $now = CarbonImmutable::now();

        return $this->ordered($this->query($filters, null, $now))
            ->with([
                'classGroup',
                'tutor',
                'lessonReports:id,lesson_id,author_role,submitted_at',
                'raisedIssues' => fn (HasMany $issues) => $issues->whereNull('acknowledged_at'),
            ])
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Lesson $lesson): array => $this->row($lesson, $now));
    }

    /**
     * The newest lesson in the filters' queue, or null when it's empty.
     *
     * @param  array{queue: string, search: string, class: int|null, tutor: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null}  $filters
     */
    public function first(array $filters): ?Lesson
    {
        return $this->ordered($this->query($filters))->first();
    }

    /**
     * @return array{id: int, className: string, tutor: string|null, day: string, time: string, state: string, stateLabel: string}
     */
    public function row(Lesson $lesson, CarbonImmutable $now): array
    {
        [$state, $stateLabel] = $this->state($lesson, $now);

        return [
            'id' => $lesson->id,
            'className' => $lesson->classGroup->name,
            'tutor' => $lesson->tutor?->name,
            'day' => $this->dayLabel($lesson->localStart(), $now),
            'time' => $lesson->localStart()->format('H:i'),
            'state' => $state,
            'stateLabel' => $stateLabel,
        ];
    }

    /**
     * Everything the detail pane shows for one lesson.
     *
     * @return array<string, mixed>
     */
    public function detail(Lesson $lesson, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $lesson->load(['classGroup', 'tutor', 'lessonReports.author', 'raisedIssues.raisedBy', 'reportsReviewedBy', 'attendanceRecords']);

        $students = $lesson->attendanceRecords->where('attendee_role', AttendeeRole::Student);
        $attended = $students->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])->count();
        $tutorReport = $lesson->lessonReports->firstWhere('author_role', AttendeeRole::Tutor);
        $studentReports = $lesson->lessonReports
            ->where('author_role', AttendeeRole::Student)
            ->sortBy([
                fn (LessonReport $a, LessonReport $b): int => $this->needsAttention($b) <=> $this->needsAttention($a),
                fn (LessonReport $a, LessonReport $b): int => $b->submitted_at <=> $a->submitted_at,
            ])
            ->values();
        $ratings = $studentReports->pluck('rating')->filter(fn (?int $rating): bool => $rating !== null);
        [$state, $stateLabel] = $this->state($lesson, $now);

        return [
            'id' => $lesson->id,
            'className' => $lesson->classGroup->name,
            'tutor' => $lesson->tutor?->name,
            'when' => $this->dayLabel($lesson->localStart(), $now).' '.$lesson->localStart()->format('H:i'),
            'attendance' => $students->isEmpty() ? null : "{$attended} of {$students->count()} present",
            'state' => $state,
            'stateLabel' => $stateLabel,
            'latestSubmittedAt' => $lesson->lessonReports->max('submitted_at')?->utc()->format('Y-m-d H:i:s'),
            'reportWindowClosesAt' => $tutorReport ? null : $this->local($lesson->ends_at->addHours(LessonPolicy::REPORT_WINDOW_HOURS)),
            'tutorReport' => $tutorReport ? $this->report($tutorReport) : null,
            'studentReports' => $studentReports->map($this->report(...))->all(),
            'studentSummary' => [
                'count' => $studentReports->count(),
                'averageRating' => $ratings->isEmpty() ? null : round($ratings->avg(), 1),
                'needsAttention' => $studentReports->filter($this->needsAttention(...))->count(),
            ],
            'raisedIssues' => $lesson->raisedIssues
                ->sortByDesc('id')
                ->values()
                ->map(fn (RaisedIssue $issue): array => [
                    'id' => $issue->id,
                    'note' => $issue->note,
                    'raisedBy' => $issue->raisedBy?->name,
                    'raisedAt' => $this->local($issue->created_at),
                    'acknowledgedAt' => $issue->acknowledged_at ? $this->local($issue->acknowledged_at) : null,
                ])
                ->all(),
            'reviewed' => $this->isReviewed($lesson) ? [
                'at' => $this->local($lesson->reports_reviewed_at),
                'by' => $lesson->reportsReviewedBy?->name,
            ] : null,
        ];
    }

    /**
     * Reviewed, with nothing submitted since.
     */
    public function isReviewed(Lesson $lesson): bool
    {
        $reviewedAt = $lesson->reports_reviewed_at;

        return $reviewedAt !== null
            && $lesson->lessonReports->every(fn (LessonReport $report): bool => $report->submitted_at->lessThanOrEqualTo($reviewedAt));
    }

    /**
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    private function ordered(Builder $query): Builder
    {
        return $query->orderByDesc('lessons.starts_at')->orderByDesc('lessons.id');
    }

    /**
     * Someone was actually there. Absent records alone don't count: the no-show job writes those for every lesson.
     *
     * @param  Builder<AttendanceRecord>  $records
     */
    private function attended(Builder $records): void
    {
        $records->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late]);
    }

    /**
     * Reports submitted after the lesson's last review (or never reviewed).
     *
     * @param  Builder<LessonReport>  $reports
     */
    private function unreviewed(Builder $reports): void
    {
        $reports->where(fn (Builder $query) => $query
            ->whereNull('lessons.reports_reviewed_at')
            ->orWhereColumn('lesson_reports.submitted_at', '>', 'lessons.reports_reviewed_at'));
    }

    /**
     * The row's status, most important first.
     *
     * @return array{0: string, 1: string}
     */
    private function state(Lesson $lesson, CarbonImmutable $now): array
    {
        $reports = $lesson->lessonReports;
        $hasTutorReport = $reports->contains('author_role', AttendeeRole::Tutor);
        $studentReports = $reports->where('author_role', AttendeeRole::Student)->count();

        return match (true) {
            $lesson->raisedIssues->whereNull('acknowledged_at')->isNotEmpty() => ['raised', 'Raised with tutor'],
            ! $hasTutorReport && $lesson->ends_at->lessThan($now) => $lesson->ends_at->addHours(LessonPolicy::REPORT_WINDOW_HOURS)->greaterThan($now)
                ? ['pending', 'Tutor report pending']
                : ['missing', 'Tutor report missing'],
            $this->isReviewed($lesson) => ['reviewed', 'Reviewed'],
            $hasTutorReport && $studentReports > 0 => ['both', 'Both sides submitted'],
            $hasTutorReport => ['tutor', 'Tutor report only'],
            $studentReports > 0 => ['students', $studentReports.' '.Str::plural('student report', $studentReports)],
            default => ['waiting', 'No reports yet'],
        };
    }

    private function needsAttention(LessonReport $report): bool
    {
        return $report->raises_issue || filled($report->issues) || ($report->rating !== null && $report->rating <= 2);
    }

    /**
     * @return array{id: int, author: string, rating: int|null, wentWell: string|null, issues: string|null, recommendations: string|null, recommendedActions: array<int, string>, raisesIssue: bool, submittedAt: string, needsAttention: bool}
     */
    private function report(LessonReport $report): array
    {
        return [
            'id' => $report->id,
            'author' => $report->author->name,
            'rating' => $report->rating,
            'wentWell' => $report->went_well,
            'issues' => $report->issues,
            'recommendations' => $report->recommendations,
            'recommendedActions' => ($report->recommended_actions ?? collect())->map(fn (RecommendedAction $action): string => $action->label())->values()->all(),
            'raisesIssue' => $report->raises_issue,
            'submittedAt' => $this->local($report->submitted_at),
            'needsAttention' => $this->needsAttention($report),
        ];
    }

    private function dayLabel(CarbonImmutable $localStart, CarbonImmutable $now): string
    {
        $today = $now->setTimezone(config('academy.timezone'))->startOfDay();
        $day = $localStart->startOfDay();

        return match (true) {
            $day->equalTo($today) => 'Today',
            $day->equalTo($today->subDay()) => 'Yesterday',
            $day->year === $today->year => $localStart->format('D j M'),
            default => $localStart->format('D j M Y'),
        };
    }

    private function local(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(config('academy.timezone'))->format('j M, H:i');
    }

    private function positiveInt(mixed $value): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $number === false ? null : $number;
    }
}
