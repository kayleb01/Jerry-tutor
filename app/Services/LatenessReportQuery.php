<?php

namespace App\Services;

use App\Enums\AttendeeRole;
use App\Models\LatenessReport;
use App\Support\AcademyDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Filters for the lateness list and its CSV export. Unknown or malformed filters are ignored.
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
            ->when($filters['from'], fn (Builder $query, CarbonImmutable $from) => $query->where('lesson_date', '>=', $from->toDateString()))
            ->when($filters['to'], fn (Builder $query, CarbonImmutable $to) => $query->where('lesson_date', '<=', $to->toDateString()))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => $user->withTrashed()->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$search])))
            ->orderByDesc('lesson_date')
            ->orderByDesc('scheduled_start_at')
            ->orderByDesc('id');
    }
}
