<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveQuestion;
use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\YearGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuestionRequest;
use App\Models\Question;
use App\Models\Strand;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class QuestionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => Str::lower(trim((string) $request->query('search'))),
            'key_stage' => KeyStage::tryFrom((string) $request->query('key_stage'))?->value,
            'strand' => Strand::where('slug', (string) $request->query('strand'))->value('slug'),
            'topic' => filter_var($request->query('topic'), FILTER_VALIDATE_INT) ?: null,
            'difficulty' => Difficulty::tryFrom((string) $request->query('difficulty'))?->value,
            'type' => QuestionType::tryFrom((string) $request->query('type'))?->value,
            'status' => QuestionStatus::tryFrom((string) $request->query('status'))?->value,
            'exam_track' => ExamTrack::tryFrom((string) $request->query('exam_track'))?->value,
        ];
        $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';

        $questions = Question::query()
            ->with(['topic.strand', 'examTrackRows'])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->whereRaw("LOWER(stem) LIKE ? ESCAPE '!'", [$search]))
            ->when($filters['key_stage'], fn (Builder $query, string $keyStage) => $query->whereHas('topic', fn (Builder $topic) => $topic->where('key_stage', $keyStage)))
            ->when($filters['strand'], fn (Builder $query, string $strand) => $query->whereHas('topic.strand', fn (Builder $builder) => $builder->where('slug', $strand)))
            ->when($filters['topic'], fn (Builder $query, int $topic) => $query->where('topic_id', $topic))
            ->when($filters['difficulty'], fn (Builder $query, string $difficulty) => $query->where('difficulty', $difficulty))
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['exam_track'], fn (Builder $query, string $track) => $query->whereHas('examTrackRows', fn (Builder $rows) => $rows->where('exam_track', $track)))
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Question $question): array => [
                'id' => $question->id,
                'stem' => $question->stem,
                'topic' => $question->topic->name,
                'strand' => $question->topic->strand->slug,
                'keyStage' => $question->topic->key_stage->label(),
                'yearGroup' => $question->year_group?->label(),
                'difficulty' => $question->difficulty->value,
                'type' => $question->type->label(),
                'status' => $question->status->value,
                'statusLabel' => $question->status->label(),
                'examTracks' => array_map(fn (ExamTrack $track): string => $track->label(), $question->examTracks()),
                'updatedAt' => $question->updated_at->setTimezone(config('academy.timezone'))->format('j M Y'),
                'canDelete' => $question->status->isDeletable(),
                'nextStatuses' => self::statusOptions($question->status->nextStatuses()),
            ]);

        $counts = Question::query()->toBase()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->whereNull('deleted_at')->pluck('aggregate', 'status');

        return Inertia::render('admin/questions/index', [
            'questions' => $questions,
            'filters' => [...$filters, 'search' => $filters['search']],
            'counts' => collect(QuestionStatus::cases())->mapWithKeys(fn (QuestionStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)]),
            'options' => self::formOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/questions/create', ['options' => self::formOptions()]);
    }

    public function store(QuestionRequest $request, SaveQuestion $saveQuestion): RedirectResponse
    {
        $question = $saveQuestion->handle($request->safe()->except(['image', 'remove_image']), $request->user(), image: $request->file('image'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Question saved as a draft.']);

        return to_route('admin.questions.edit', $question);
    }

    public function edit(Question $question): Response
    {
        $question->load(['options', 'examTrackRows', 'author', 'reviewedBy']);

        return Inertia::render('admin/questions/edit', [
            'options' => self::formOptions(),
            'question' => [
                'id' => $question->id,
                'topicId' => $question->topic_id,
                'difficulty' => $question->difficulty->value,
                'yearGroup' => $question->year_group?->value,
                'type' => $question->type->value,
                'stem' => $question->stem,
                'options' => $question->options->pluck('body')->all(),
                'correct' => $question->options->values()->filter->is_correct->keys()->all(),
                'numericAnswer' => $question->numeric_answer === null ? null : rtrim(rtrim($question->numeric_answer, '0'), '.'),
                'numericTolerance' => rtrim(rtrim($question->numeric_tolerance, '0'), '.') ?: '0',
                'acceptedAnswers' => $question->accepted_answers ?? [],
                'explanation' => $question->explanation,
                'marks' => $question->marks,
                'examTracks' => array_map(fn (ExamTrack $track): string => $track->value, $question->examTracks()),
                'imageUrl' => $question->image_path ? Storage::disk('public')->url($question->image_path) : null,
                'status' => $question->status->value,
                'statusLabel' => $question->status->label(),
                'nextStatuses' => self::statusOptions($question->status->nextStatuses()),
                'author' => $question->author?->name,
                'reviewedBy' => $question->reviewedBy?->name,
                'publishedAt' => $question->published_at?->setTimezone(config('academy.timezone'))->format('j M Y'),
                'canDelete' => $question->status->isDeletable(),
            ],
        ]);
    }

    public function update(QuestionRequest $request, Question $question, SaveQuestion $saveQuestion): RedirectResponse
    {
        $saveQuestion->handle(
            $request->safe()->except(['image', 'remove_image']),
            $request->user(),
            $question,
            $request->file('image'),
            $request->boolean('remove_image'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Question saved.']);

        return to_route('admin.questions.edit', $question);
    }

    public function destroy(Question $question): RedirectResponse
    {
        if (! $question->status->isDeletable()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$question->status->label()} questions can't be deleted. Retire it instead."]);

            return back();
        }

        $question->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Question deleted.']);

        return to_route('admin.questions.index');
    }

    /**
     * @param  array<int, QuestionStatus>  $statuses
     * @return array<int, array{value: string, label: string}>
     */
    public static function statusOptions(array $statuses): array
    {
        return array_map(fn (QuestionStatus $status): array => ['value' => $status->value, 'label' => $status->label()], $statuses);
    }

    /**
     * @return array<string, mixed>
     */
    public static function formOptions(): array
    {
        return [
            'keyStages' => KeyStage::options(),
            'strands' => Strand::orderBy('sort_order')->get()->map(fn (Strand $strand): array => ['value' => $strand->slug, 'label' => $strand->name])->all(),
            'topics' => Topic::with('strand')->orderBy('key_stage')->orderBy('sort_order')->get()->map(fn (Topic $topic): array => [
                'value' => $topic->id,
                'label' => $topic->name,
                'keyStage' => $topic->key_stage->value,
                'strand' => $topic->strand->slug,
            ])->all(),
            'difficulties' => Difficulty::options(),
            'types' => QuestionType::options(),
            'statuses' => QuestionStatus::options(),
            'examTracks' => ExamTrack::options(),
            'yearGroups' => array_map(fn (YearGroup $year): array => ['value' => $year->value, 'label' => $year->label(), 'keyStage' => $year->keyStage()->value], YearGroup::cases()),
        ];
    }
}
