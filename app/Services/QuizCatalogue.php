<?php

namespace App\Services;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\QuizAttemptStatus;
use App\Enums\YearGroup;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which quizzes a student sees, grouped for the quizzes home, with their scores so far.
 */
class QuizCatalogue
{
    /** Scores at or above this (percent) count towards unlocking the Challenge tier. */
    public const CHALLENGE_SCORE = 80;

    public const CHALLENGE_QUIZZES_NEEDED = 3;

    public function __construct(private QuestionPicker $picker) {}

    /**
     * @return array<int, array{key: string, title: string, locked: bool, progress: array{done: int, needed: int}|null, quizzes: array<int, array<string, mixed>>}>
     */
    public function sectionsFor(User $student): array
    {
        $year = $student->studentProfile?->year_group;

        if ($year === null) {
            return [];
        }

        $attempts = QuizAttempt::query()->where('user_id', $student->id)->with('quiz')->latest('id')->get()->groupBy('quiz_id');
        $sections = [];

        if ($year->keyStage() === KeyStage::Ks4) {
            $sections[] = $this->section('gcse', 'GCSE', $this->published()->where('exam_track', ExamTrack::Gcse), $attempts);

            return $sections;
        }

        $sections[] = $this->section('year', "{$year->label()} quizzes", $this->published()->where('year_group', $year), $attempts);

        if (in_array($year, [YearGroup::Year5, YearGroup::Year6], true)) {
            $sections[] = $this->section('exam', 'Exam practice', $this->published()->whereIn('exam_track', [ExamTrack::ElevenPlus, ExamTrack::Sats]), $attempts);
        }

        if ($year->keyStage() === KeyStage::Ks3) {
            $done = $this->strongScores($student, $year);
            $unlocked = $done >= self::CHALLENGE_QUIZZES_NEEDED;
            $challenge = $this->section('challenge', 'Challenge', $this->published()->where('key_stage', KeyStage::Ks3)->where('difficulty', Difficulty::Challenging), $attempts);

            $sections[] = [
                ...$challenge,
                'locked' => ! $unlocked,
                'progress' => $unlocked ? null : ['done' => $done, 'needed' => self::CHALLENGE_QUIZZES_NEEDED],
                'quizzes' => $unlocked ? $challenge['quizzes'] : [],
            ];
        }

        return $sections;
    }

    /**
     * The student's latest finished quizzes.
     *
     * @return Collection<int, array{id: int, title: string, score: string, date: string}>
     */
    public function recentFor(User $student, int $limit = 5): Collection
    {
        return QuizAttempt::query()
            ->where('user_id', $student->id)
            ->where('status', QuizAttemptStatus::Submitted)
            ->with('quiz')
            ->latest('submitted_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (QuizAttempt $attempt): array => [
                'id' => $attempt->id,
                'title' => $attempt->quiz->title,
                'score' => "{$attempt->score}/{$attempt->max_score}",
                'date' => $attempt->submitted_at->setTimezone(config('academy.timezone'))->format('j M'),
            ]);
    }

    /**
     * The quiz slug for a strand at the student's level, e.g. "year-5-number" or "gcse-number".
     */
    public static function strandQuizSlug(?YearGroup $year, string $strand): ?string
    {
        return match (true) {
            $year === null => null,
            $year->keyStage() === KeyStage::Ks4 => "gcse-{$strand}",
            default => 'year-'.$year->value."-{$strand}",
        };
    }

    /**
     * @return Builder<Quiz>
     */
    private function published(): Builder
    {
        return Quiz::query()->where('is_published', true)->with('strand')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<Quiz>  $quizzes
     * @param  Collection<int, Collection<int, QuizAttempt>>  $attempts  by quiz id, newest first
     * @return array{key: string, title: string, locked: bool, progress: null, quizzes: array<int, array<string, mixed>>}
     */
    private function section(string $key, string $title, Builder $quizzes, Collection $attempts): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'locked' => false,
            'progress' => null,
            'quizzes' => $quizzes->get()->map(function (Quiz $quiz) use ($attempts): array {
                $mine = $attempts->get($quiz->id, collect());
                $finished = $mine->where('status', QuizAttemptStatus::Submitted);
                $last = $finished->sortByDesc(fn (QuizAttempt $attempt) => $attempt->submitted_at)->first();

                return [
                    'slug' => $quiz->slug,
                    'title' => $quiz->title,
                    'strand' => $quiz->strand?->slug,
                    'available' => $this->picker->poolSize($quiz),
                    'lastScore' => $last ? "{$last->score}/{$last->max_score}" : null,
                    'bestPercentage' => $finished->isEmpty() ? null : $finished->max(fn (QuizAttempt $attempt): int => $attempt->percentage() ?? 0),
                    'inProgress' => $mine->contains('status', QuizAttemptStatus::InProgress),
                ];
            })->values()->all(),
        ];
    }

    private function strongScores(User $student, YearGroup $year): int
    {
        return QuizAttempt::query()
            ->where('user_id', $student->id)
            ->where('status', QuizAttemptStatus::Submitted)
            ->whereHas('quiz', fn (Builder $quiz) => $quiz->where('year_group', $year))
            ->get(['score', 'max_score'])
            ->filter(fn (QuizAttempt $attempt): bool => ($attempt->percentage() ?? 0) >= self::CHALLENGE_SCORE)
            ->count();
    }
}
