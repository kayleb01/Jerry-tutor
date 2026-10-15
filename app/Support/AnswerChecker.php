<?php

namespace App\Support;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionOption;

/**
 * Decides whether a saved response answers a question, forgiving the ways children type answers.
 */
final class AnswerChecker
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public static function isCorrect(Question $question, ?array $response): bool
    {
        if ($response === null) {
            return false;
        }

        $correctOptions = fn (): array => $question->options->where('is_correct', true)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        return match ($question->type) {
            QuestionType::MultipleChoice => in_array((int) ($response['option'] ?? 0), $correctOptions(), true),
            QuestionType::MultiSelect => collect((array) ($response['options'] ?? []))->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all() === $correctOptions(),
            QuestionType::Numeric => self::numberMatches((string) ($response['value'] ?? ''), (float) $question->numeric_answer, (float) $question->numeric_tolerance),
            QuestionType::ShortText => in_array(self::normaliseText((string) ($response['text'] ?? '')), array_map(self::normaliseText(...), $question->accepted_answers ?? []), true),
        };
    }

    /**
     * "1,000", " +42 ", ".5", "3/4" and "-5" are all read as numbers; anything else is wrong.
     */
    public static function parseNumber(string $typed): ?float
    {
        $clean = str_replace([',', ' ', "\u{00a0}"], '', trim($typed));
        $clean = ltrim($clean, '+');

        if (preg_match('/^(-?\d+(?:\.\d+)?)\/(\d+(?:\.\d+)?)$/', $clean, $fraction)) {
            return (float) $fraction[2] === 0.0 ? null : (float) $fraction[1] / (float) $fraction[2];
        }

        return preg_match('/^-?(\d+\.?\d*|\.\d+)$/', $clean) ? (float) $clean : null;
    }

    public static function numberMatches(string $typed, float $answer, float $tolerance): bool
    {
        $value = self::parseNumber($typed);

        return $value !== null && abs($value - $answer) <= $tolerance + 1e-9;
    }

    /**
     * Lower case, single spaces, no spaces around "=", no trailing full stop.
     */
    public static function normaliseText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = (string) preg_replace('/\s*=\s*/u', '=', $text);

        return rtrim($text, '. ');
    }

    /**
     * The response as the student gave it, for the results page.
     *
     * @param  array<string, mixed>|null  $response
     */
    public static function describeResponse(Question $question, ?array $response): ?string
    {
        if ($response === null) {
            return null;
        }

        return match ($question->type) {
            QuestionType::MultipleChoice => $question->options->firstWhere('id', (int) ($response['option'] ?? 0))?->body,
            QuestionType::MultiSelect => $question->options
                ->whereIn('id', array_map('intval', (array) ($response['options'] ?? [])))
                ->sortBy('sort_order')
                ->pluck('body')
                ->join(', '),
            QuestionType::Numeric => (string) ($response['value'] ?? ''),
            QuestionType::ShortText => (string) ($response['text'] ?? ''),
        };
    }

    public static function describeAnswer(Question $question): string
    {
        return match ($question->type) {
            QuestionType::MultipleChoice, QuestionType::MultiSelect => $question->options
                ->where('is_correct', true)
                ->sortBy('sort_order')
                ->map(fn (QuestionOption $option): string => $option->body)
                ->join(', '),
            QuestionType::Numeric => self::formatNumber((float) $question->numeric_answer)
                .((float) $question->numeric_tolerance > 0 ? ' (±'.self::formatNumber((float) $question->numeric_tolerance).')' : ''),
            QuestionType::ShortText => (string) ($question->accepted_answers[0] ?? ''),
        };
    }

    private static function formatNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }
}
