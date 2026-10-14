<?php

namespace App\Actions;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\QuestionType;
use App\Models\Topic;
use App\Models\User;
use App\Support\QuestionInput;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Bulk upload from a spreadsheet. Every row is checked with the same rules as the form;
 * the file is imported as drafts only when every row passes.
 */
class ImportQuestions
{
    public const MAX_ROWS = 500;

    public const REQUIRED = ['key_stage', 'strand', 'topic', 'difficulty', 'type', 'stem'];

    public const HEADERS = ['key_stage', 'strand', 'topic', 'year_group', 'difficulty', 'type', 'stem', 'option_a', 'option_b', 'option_c', 'option_d', 'option_e', 'option_f', 'correct', 'numeric_answer', 'numeric_tolerance', 'accepted_answers', 'explanation', 'marks', 'exam_tracks'];

    /** @var list<array<string, string>> */
    public const EXAMPLES = [
        ['key_stage' => 'KS2', 'strand' => 'Number', 'topic' => 'Fractions', 'year_group' => '5', 'difficulty' => 'intermediate', 'type' => 'multiple_choice', 'stem' => 'What is $\frac{1}{2} + \frac{1}{4}$?', 'option_a' => '$\frac{3}{4}$', 'option_b' => '$\frac{2}{6}$', 'option_c' => '$\frac{1}{6}$', 'option_d' => '$\frac{2}{4}$', 'correct' => 'A', 'explanation' => 'Make the denominators the same: $\frac{2}{4} + \frac{1}{4} = \frac{3}{4}$.', 'marks' => '1'],
        ['key_stage' => 'KS2', 'strand' => 'Number', 'topic' => 'Multiplication & division', 'year_group' => '6', 'difficulty' => 'hard', 'type' => 'numeric', 'stem' => 'What is $12 \times 15$?', 'numeric_answer' => '180', 'numeric_tolerance' => '0', 'explanation' => '$12 \times 15 = 12 \times 10 + 12 \times 5 = 120 + 60 = 180$.', 'marks' => '1', 'exam_tracks' => 'SATs'],
        ['key_stage' => 'KS3', 'strand' => 'Algebra', 'topic' => 'Equations', 'year_group' => '8', 'difficulty' => 'intermediate', 'type' => 'short_text', 'stem' => 'Solve $2x + 3 = 11$. Write your answer as x = …', 'accepted_answers' => 'x = 4|x=4|4', 'explanation' => 'Subtract 3 from both sides, then divide by 2.', 'marks' => '1'],
        ['key_stage' => 'KS3', 'strand' => 'Statistics', 'topic' => 'Probability', 'year_group' => '7', 'difficulty' => 'easy', 'type' => 'multi_select', 'stem' => 'Which of these are equal to $\frac{1}{2}$?', 'option_a' => '0.5', 'option_b' => '50%', 'option_c' => '0.2', 'option_d' => '$\frac{2}{4}$', 'correct' => 'A;B;D', 'explanation' => '0.2 is one fifth.', 'marks' => '1'],
    ];

    public function __construct(private SaveQuestion $saveQuestion) {}

    /**
     * @return array{imported: int, errors: list<string>}
     */
    public function handle(string $path, User $author): array
    {
        $rows = $this->readRows($path);

        if (is_string($rows)) {
            return ['imported' => 0, 'errors' => [$rows]];
        }

        $topics = Topic::with('strand')->get();
        $prepared = [];
        $errors = [];

        foreach ($rows as $line => $row) {
            [$data, $rowErrors] = $this->prepare($row, $topics);

            if ($rowErrors === []) {
                $rowErrors = Validator::make($data, QuestionInput::rules($data), QuestionInput::messages(), QuestionInput::attributes())
                    ->after(QuestionInput::after($data))
                    ->errors()
                    ->all();
            }

            foreach ($rowErrors as $message) {
                $errors[] = "Row {$line}: {$message}";
            }

            $prepared[] = $data;
        }

        if ($errors !== []) {
            return ['imported' => 0, 'errors' => array_slice($errors, 0, 50)];
        }

        DB::transaction(function () use ($prepared, $author): void {
            foreach ($prepared as $data) {
                $this->saveQuestion->handle($data, $author);
            }
        });

        return ['imported' => count($prepared), 'errors' => []];
    }

    /**
     * @return array<int, array<string, string>>|string rows keyed by spreadsheet row number, or the problem with the file
     */
    private function readRows(string $path): array|string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path));

        // Excel's plain "CSV" export on Windows is Windows-1252, not UTF-8 (e.g. "£" is a single 0xA3 byte).
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            return 'The file has no questions in it.';
        }

        $header = array_map(fn (?string $cell): string => strtolower(trim((string) $cell)), $header);
        $missing = array_values(array_diff(self::REQUIRED, $header));

        if ($missing !== []) {
            return 'The header row is missing: '.implode(', ', $missing).'. Download the template to see the columns.';
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if (trim(implode('', array_map('strval', $cells))) === '') {
                continue;
            }

            if (count($rows) === self::MAX_ROWS) {
                return 'The file has more than '.self::MAX_ROWS.' questions. Split it into smaller files.';
            }

            $cells = array_pad(array_slice($cells, 0, count($header)), count($header), '');
            $rows[$line] = array_map(fn (?string $cell): string => trim((string) $cell), array_combine($header, $cells));
        }

        fclose($handle);

        return $rows === [] ? 'The file has no questions in it.' : $rows;
    }

    /**
     * Turn one spreadsheet row into QuestionInput data, collecting problems the rules can't see (unknown names).
     *
     * @param  array<string, string>  $row
     * @param  Collection<int, Topic>  $topics
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function prepare(array $row, Collection $topics): array
    {
        $errors = [];
        $keyStage = $this->enumFrom(KeyStage::class, $row['key_stage']);
        $topic = null;

        if (! $keyStage) {
            $errors[] = "Key stage “{$row['key_stage']}” isn’t one of KS2, KS3 or GCSE.";
        } else {
            $topic = $topics->first(fn (Topic $topic): bool => $topic->key_stage === $keyStage
                && strtolower($topic->strand->name) === strtolower($row['strand'])
                && strtolower($topic->name) === strtolower($row['topic']));

            if (! $topic) {
                $strand = ucfirst(strtolower($row['strand']));
                $errors[] = "There’s no {$keyStage->label()} {$strand} topic called “{$row['topic']}”.";
            }
        }

        $correct = [];

        foreach (preg_split('/[\s;,|]+/', strtolower($row['correct'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $letter) {
            if (! preg_match('/^[a-f]$/', $letter)) {
                $errors[] = 'Correct answers are option letters A to F, like B or A;C.';
                break;
            }

            $correct[] = ord($letter) - ord('a');
        }

        $tracks = [];

        foreach (preg_split('/\s*[|;]\s*/', $row['exam_tracks'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $name) {
            $track = $this->enumFrom(ExamTrack::class, $name);

            if ($track) {
                $tracks[] = $track->value;
            } else {
                $errors[] = "Exam track “{$name}” isn’t one of 11+, SATs or GCSE.";
            }
        }

        $data = QuestionInput::normalise([
            'topic_id' => $topic?->id,
            'difficulty' => $this->enumFrom(Difficulty::class, $row['difficulty'])?->value ?? $row['difficulty'],
            'year_group' => $row['year_group'] ?? '',
            'type' => $this->enumFrom(QuestionType::class, $row['type'])?->value ?? $row['type'],
            'stem' => $row['stem'],
            'options' => array_map(fn (string $letter): string => $row["option_{$letter}"] ?? '', ['a', 'b', 'c', 'd', 'e', 'f']),
            'correct' => $correct,
            'numeric_answer' => $row['numeric_answer'] ?? '',
            'numeric_tolerance' => $row['numeric_tolerance'] ?? '',
            'accepted_answers' => preg_split('/\s*[|;]\s*/', $row['accepted_answers'] ?? '', -1, PREG_SPLIT_NO_EMPTY),
            'explanation' => $row['explanation'] ?? '',
            'marks' => $row['marks'] ?? '',
            'exam_tracks' => $tracks,
        ]);

        return [$data, $errors];
    }

    /**
     * Match "KS2", "ks2", "Multiple choice", "multi-select", "eleven_plus" or "11+" to an enum case.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enumFrom(string $enum, string $input): ?BackedEnum
    {
        $needle = str_replace(['-', '_'], ' ', strtolower(trim($input)));

        foreach ($enum::cases() as $case) {
            if (in_array($needle, [str_replace('_', ' ', strtolower((string) $case->value)), strtolower($case->label())], true)) {
                return $case;
            }
        }

        return null;
    }
}
