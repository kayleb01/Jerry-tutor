<?php

use App\Actions\ImportQuestions;
use App\Enums\ExamTrack;
use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->seed(CurriculumSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function csvFile(array $rows, ?array $header = null): UploadedFile
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header ?? ImportQuestions::HEADERS, escape: '');

    foreach ($rows as $row) {
        $values = array_map(fn (string $column) => $row[$column] ?? '', $header ?? ImportQuestions::HEADERS);
        fputcsv($handle, $values, escape: '');
    }

    rewind($handle);

    return UploadedFile::fake()->createWithContent('questions.csv', "\xEF\xBB\xBF".stream_get_contents($handle));
}

function csvRow(array $overrides = []): array
{
    return [
        'key_stage' => 'KS2',
        'strand' => 'number',
        'topic' => 'fractions',
        'year_group' => '5',
        'difficulty' => 'Intermediate',
        'type' => 'Multiple choice',
        'stem' => 'What is $\frac{1}{2} + \frac{1}{4}$?',
        'option_a' => '$\frac{3}{4}$',
        'option_b' => '$\frac{2}{6}$',
        'option_c' => '$\frac{1}{6}$',
        'correct' => 'a',
        'explanation' => 'Make the denominators the same.',
        'exam_tracks' => 'SATs | 11+',
        ...$overrides,
    ];
}

it('imports every row as a draft', function () {
    $file = csvFile([
        csvRow(),
        csvRow(['type' => 'numeric', 'stem' => 'What is $12 \times 15$?', 'option_a' => '', 'option_b' => '', 'option_c' => '', 'correct' => '', 'numeric_answer' => '180', 'exam_tracks' => '']),
        csvRow(['key_stage' => 'ks3', 'strand' => 'Algebra', 'topic' => 'Equations', 'year_group' => '8', 'type' => 'short_text', 'stem' => 'Solve $2x + 3 = 11$', 'option_a' => '', 'option_b' => '', 'option_c' => '', 'correct' => '', 'accepted_answers' => 'x = 4|x=4|4', 'exam_tracks' => '']),
        csvRow(['type' => 'multi-select', 'option_d' => '$\frac{6}{8}$', 'correct' => 'A; D']),
    ]);

    $this->actingAs($this->admin)->post(route('admin.questions.import.store'), ['file' => $file])
        ->assertRedirect(route('admin.questions.index', ['status' => 'draft']))
        ->assertInertiaFlash('toast.message', '4 questions imported as drafts. Review them, then publish.');

    $questions = Question::with('options')->orderBy('id')->get();

    expect($questions)->toHaveCount(4)
        ->and($questions->pluck('status')->unique()->all())->toBe([QuestionStatus::Draft])
        ->and($questions[0]->examTracks())->toBe([ExamTrack::ElevenPlus, ExamTrack::Sats])
        ->and($questions[0]->options->pluck('is_correct')->all())->toBe([true, false, false])
        ->and((float) $questions[1]->numeric_answer)->toBe(180.0)
        ->and($questions[2]->accepted_answers)->toBe(['x = 4', 'x=4', '4'])
        ->and($questions[3]->options->where('is_correct', true)->pluck('sort_order')->all())->toBe([0, 3]);
});

it('imports nothing when any row has a problem and names the rows', function () {
    $file = csvFile([
        csvRow(),
        csvRow(['correct' => 'A;B']),
        csvRow(['topic' => 'Dragons']),
        csvRow(['year_group' => '9']),
        csvRow(['correct' => 'Z']),
    ]);

    $this->actingAs($this->admin)->post(route('admin.questions.import.store'), ['file' => $file])
        ->assertSessionHasErrors(['file' => 'Nothing was imported. Fix the 4 rows below and upload the file again.'])
        ->assertInertiaFlash('importErrors', [
            'Row 3: A multiple-choice question has exactly one correct answer.',
            'Row 4: There’s no KS2 Number topic called “Dragons”.',
            "Row 5: Year 9 isn't in KS2. Choose a KS2 year or leave it blank.",
            'Row 6: Correct answers are option letters A to F, like B or A;C.',
        ]);

    expect(Question::count())->toBe(0);
});

it('rejects a file without the required columns, an empty file and too many rows', function () {
    $this->actingAs($this->admin)->post(route('admin.questions.import.store'), ['file' => csvFile([csvRow()], ['strand', 'topic', 'stem'])])
        ->assertInertiaFlash('importErrors', ['The header row is missing: key_stage, difficulty, type. Download the template to see the columns.']);

    $this->actingAs($this->admin)->post(route('admin.questions.import.store'), ['file' => csvFile([])])
        ->assertInertiaFlash('importErrors', ['The file has no questions in it.']);

    $this->actingAs($this->admin)->post(route('admin.questions.import.store'), ['file' => csvFile(array_fill(0, ImportQuestions::MAX_ROWS + 1, csvRow()))])
        ->assertInertiaFlash('importErrors', ['The file has more than 500 questions. Split it into smaller files.']);

    $this->actingAs($this->admin)->post(route('admin.questions.import.store'), ['file' => UploadedFile::fake()->create('questions.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors(['file']);

    expect(Question::count())->toBe(0);
});

it('offers a template whose example rows import cleanly', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.questions.import.template'));
    $response->assertOk()->assertDownload('question-bank-template.csv');

    $file = UploadedFile::fake()->createWithContent('template.csv', $response->streamedContent());
    $result = app(ImportQuestions::class)->handle($file->getRealPath(), $this->admin);

    expect($result)->toBe(['imported' => count(ImportQuestions::EXAMPLES), 'errors' => []]);
});

it('is only for admins', function () {
    $this->actingAs(User::factory()->tutor()->create())->get(route('admin.questions.import'))->assertForbidden();
});
