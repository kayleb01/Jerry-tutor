<?php

namespace App\Services;

use App\Enums\AttendeeRole;
use App\Models\LatenessReport;
use App\Models\User;
use App\Support\AcademyDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Filters for the lateness list and its CSV export, plus the repeat-lateness summary. Unknown or malformed filters are ignored.
 */
class LatenessReportQuery
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{role: AttendeeRole|null, class: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null, search: string}
     */
    public function filters(array $input): array
    {
        return [
            'role' => AttendeeRole::tryFrom(is_string($input['role'] ?? null) ? $input['role'] : ''),
            'class' => filter_var($input['class'] ?? null, FILTER_VALIDATE_INT) ?: null,
            'from' => AcademyDate::fromQuery($input['from'] ?? null),
            'to' => AcademyDate::fromQuery($input['to'] ?? null),
            'search' => is_string($input['search'] ?? null) ? Str::lower(trim($input['search'])) : '',
        ];
    }

    /**
     * @param  array{role: AttendeeRole|null, class: int|null, from: CarbonImmutable|null, to: CarbonImmutable|null, search: string}  $filters
     * @return Builder<LatenessReport>
     */
    public function filtered(array $filters): Builder
    {
        // "!" as the LIKE escape character behaves the same on SQLite, MySQL and PostgreSQL.
        $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';

        return LatenessReport::query()
            ->with(['user', 'reviewedBy'])
            ->when($filters['role'], fn (Builder $query, AttendeeRole $role) => $query->where('attendee_role', $role))
            ->when($filters['class'], fn (Builder $query, int $class) => $query->whereHas('lesson', fn (Builder $lesson) => $lesson->where('class_group_id', $class)))
            ->when($filters['from'], fn (Builder $query, CarbonImmutable $from) => $query->whereDate('lesson_date', '>=', $from->toDateString()))
            ->when($filters['to'], fn (Builder $query, CarbonImmutable $to) => $query->whereDate('lesson_date', '<=', $to->toDateString()))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => $user->withTrashed()->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$search])))
            ->orderByDesc('lesson_date')
            ->orderByDesc('scheduled_start_at')
            ->orderByDesc('id');
    }

    /**
     * People late 3 or more times between two academy dates (default: the last 30 days), most frequent first.
     * An optional scope narrows the reports further, e.g. to one class.
     *
     * @param  (callable(Builder<LatenessReport>): mixed)|null  $scope
     * @return array<int, array{userId: int, name: string, role: string, count: int, lastDate: string}>
     */
    public function repeatOffenders(?string $since = null, ?string $until = null, ?callable $scope = null): array
    {
        $since ??= CarbonImmutable::now(config('academy.timezone'))->subDays(30)->toDateString();

        $rows = LatenessReport::query()
            ->select('user_id', 'attendee_role', DB::raw('COUNT(*) as late_count'), DB::raw('MAX(lesson_date) as last_date'))
            ->whereDate('lesson_date', '>=', $since)
            ->when($until, fn (Builder $query) => $query->whereDate('lesson_date', '<=', $until))
            ->when($scope, fn (Builder $query) => $scope($query))
            ->groupBy('user_id', 'attendee_role')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('late_count')
            ->orderByDesc('last_date')
            ->get();

        $names = User::withTrashed()->whereKey($rows->pluck('user_id'))->pluck('name', 'id');

        return $rows->map(fn (LatenessReport $row): array => [
            'userId' => (int) $row->user_id,
            'name' => $names[$row->user_id] ?? 'Unknown',
            'role' => $row->attendee_role->value,
            'count' => (int) $row->getAttribute('late_count'),
            'lastDate' => CarbonImmutable::parse((string) $row->getAttribute('last_date'))->format('D j M'),
        ])->values()->all();
    }
}
