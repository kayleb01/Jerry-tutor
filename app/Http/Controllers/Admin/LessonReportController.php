<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\RaisedIssue;
use App\Models\User;
use App\Services\ClassReportInbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LessonReportController extends Controller
{
    /**
     * The end-of-class reports inbox: lessons in the chosen queue on the left, one lesson's reports on the right.
     */
    public function index(Request $request, ClassReportInbox $inbox): Response
    {
        $filters = $inbox->filters($request->query());
        $selectedId = filter_var($request->query('lesson'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        return Inertia::render('admin/lesson-reports/index', [
            'lessons' => Inertia::scroll(fn () => $inbox->paginate($filters)),
            'counts' => fn () => $inbox->counts($filters),
            'filters' => [
                ...$filters,
                'from' => $filters['from']?->toDateString(),
                'to' => $filters['to']?->toDateString(),
                'lesson' => $selectedId,
            ],
            'queues' => collect(ClassReportInbox::QUEUES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            'classes' => fn () => ClassGroup::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassGroup $group): array => ['value' => $group->id, 'label' => $group->name]),
            'tutors' => fn () => User::query()->approvedTutors()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $tutor): array => ['value' => $tutor->id, 'label' => $tutor->name]),
            'selected' => function () use ($inbox, $filters, $selectedId): ?array {
                $lesson = $selectedId ? Lesson::find($selectedId) : $inbox->first($filters);

                return $lesson ? $inbox->detail($lesson) : null;
            },
        ]);
    }

    /**
     * Mark a lesson's reports as reviewed, then open the next lesson the page asked for.
     */
    public function review(Request $request, Lesson $lesson): RedirectResponse
    {
        $validated = $request->validate([
            'next' => ['nullable', 'integer', 'min:1'],
            'seen' => ['nullable', 'date'],
        ]);

        if ($this->hasUnseenReports($lesson, $validated['seen'] ?? null)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'A new report arrived while you were reading. Have a look, then mark it as reviewed.']);

            return redirect($this->inboxUrl($lesson->id));
        }

        $this->markReviewed($lesson, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$lesson->title()} marked as reviewed."]);

        return redirect($this->inboxUrl($validated['next'] ?? null));
    }

    /**
     * Send the lesson's tutor a note they'll see on their dashboard. Raising it counts as reviewing the lesson,
     * unless a report arrived after the admin opened it.
     */
    public function raise(Request $request, Lesson $lesson): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
            'seen' => ['nullable', 'date'],
        ]);
        $unseen = $this->hasUnseenReports($lesson, $validated['seen'] ?? null);

        DB::transaction(function () use ($lesson, $request, $validated, $unseen): void {
            RaisedIssue::create([
                'lesson_id' => $lesson->id,
                'tutor_id' => $lesson->tutor_id,
                'raised_by_id' => $request->user()->id,
                'note' => trim($validated['note']),
            ]);
            if (! $unseen) {
                $this->markReviewed($lesson, $request->user());
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sent to {$lesson->tutor->name}."]);

        return redirect($this->inboxUrl($lesson->id));
    }

    /**
     * Whether a report was submitted after the newest one the admin's page showed (`seen`, UTC).
     */
    private function hasUnseenReports(Lesson $lesson, ?string $seen): bool
    {
        return $lesson->lessonReports()
            ->when($seen !== null, fn ($reports) => $reports->where('submitted_at', '>', CarbonImmutable::parse($seen, 'UTC')))
            ->exists();
    }

    private function markReviewed(Lesson $lesson, User $admin): void
    {
        $lesson->forceFill(['reports_reviewed_at' => now(), 'reports_reviewed_by_id' => $admin->id])->save();
    }

    /**
     * The inbox with the filters the admin came from (and nothing else from that URL), opened on a lesson.
     */
    private function inboxUrl(?int $lessonId): string
    {
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);
        $query = Arr::only($query, ['queue', 'search', 'class', 'tutor', 'from', 'to']);

        if ($lessonId !== null) {
            $query['lesson'] = $lessonId;
        }

        return route('admin.lesson-reports.index', $query);
    }
}
