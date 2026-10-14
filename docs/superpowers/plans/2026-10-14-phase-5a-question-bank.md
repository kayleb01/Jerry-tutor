# Phase 5a: Question Bank (curriculum, authoring, review workflow, CSV upload)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins a maths question bank they fill themselves, by form or CSV upload, with maths notation rendered by KaTeX and a draft → review → published workflow. Phase 5b (quiz recipes, `QuestionPicker`, the student player and marking) builds on it.

**Architecture:**

- **Curriculum:** `strands` and `topics` tables, filled by an idempotent `CurriculumSeeder` (National Curriculum KS2, KS3 and GCSE topics under four strands: Number, Algebra, Statistics, Geometry).
- **Questions:** `questions`, `question_options` and `exam_track_question` tables. One input pipeline, `App\Support\QuestionInput` (normalise → rules → after-checks), is shared by the admin form request and the CSV importer, so both enforce identical rules. `App\Actions\SaveQuestion` is the only writer of a question, its options and its exam tracks.
- **Workflow:** the `QuestionStatus` enum owns the allowed transitions. `App\Actions\ChangeQuestionStatus` applies one, recording the reviewer and the first publish time. It is used by single and bulk status changes.
- **CSV upload:** `App\Actions\ImportQuestions` validates every row first, then imports all rows as drafts in one transaction, or nothing at all if any row has a problem.
- **Maths rendering:** a `MathText` React component renders `$…$` (inline) and `$$…$$` (display) segments with KaTeX. Everything else is plain text with line breaks.

**Tech Stack:** Laravel 13, Inertia v3, React 19, Wayfinder, Tailwind v4, Pest 5, and `katex` (npm, approved as D3 on 2026-10-14).

**Spec:**

- [docs/architecture/database-schema.md](../../architecture/database-schema.md) §2 (enums) and §5 (`strands`, `topics`, `questions`, `question_options`, `exam_track_question`)
- [docs/plans/implementation-plan.md](../../plans/implementation-plan.md) Phase 5, D3 and D6
- [docs/design/design-brief.md](../../design/design-brief.md) §1 and §6
- User answers on 2026-10-14: KaTeX yes; questions live in the DB; admins upload the questions.

## Global Constraints

- **Who:** only admins (the existing `role:admin` route group) can see or change the bank. Questions created by form or CSV always start as **Draft**. Only **Published** questions count towards the bank on the dashboard (and will be served in 5b).
- **Transitions:** Draft → In review; In review → Draft or Published; Published → Retired; Retired → Draft. Nothing else is allowed. Publishing records `reviewed_by_id` (the acting admin) and sets `published_at` the first time only.
- **Deleting:** only Draft and In review questions can be deleted (soft delete). Published ones must be retired instead, so the history stays intact.
- **Question types:**
    - **Multiple choice:** 2–6 options with exactly one correct.
    - **Multi-select:** 2–6 options, at least one correct and at least one wrong.
    - **Numeric:** an answer, plus a tolerance ≥ 0 that defaults to 0.
    - **Short text:** 1–10 accepted answers, each up to 100 characters (matched case-insensitively in 5b).
    - Fields a type doesn't use are dropped, never stored.
- **Limits:** stem and explanation up to 5000 characters; each option up to 500 characters; marks 1–5, default 1.
- **Year group:** optional, but if set it must belong to the topic's key stage (a Year 5 question can't sit in a KS3 topic).
- **Images:** optional png/jpg/webp up to 2 MB (no SVG), stored on the `public` disk under `questions/`. Replacing or removing an image deletes the old file.
- **CSV:**
    - **Limits and encoding:** UTF-8, at most 500 rows and 2 MB, with an optional BOM. Columns are matched by header name in any order, and unknown columns are ignored.
    - **All or nothing:** every row is validated first. Any problem means nothing is imported, and the admin sees up to 50 `Row N: …` messages, where N is the spreadsheet row number with the header as row 1.
    - **Matching values:** key stage, strand, topic, difficulty, type and exam tracks are matched case-insensitively against values or labels.
- **Maths:** stems, options and explanations use `$…$`/`$$…$$` for maths, and `\$` gives a literal dollar sign. KaTeX runs with `throwOnError: false` and `trust: false`.
- **Local database:** never run `migrate:fresh`. Use `php artisan migrate`. The new seeders are additive and idempotent (memory `feedback-local-db`).
- **Staging:** stage explicit paths only (memory `feedback-staging`).
- **Conventions:** Wayfinder imports, design-system components, UK English, `FieldError` plus `aria-describedby` on every input.
- **Before each commit:** run `vendor/bin/pint --dirty --format agent`, then `npm run check:fix && npm run types:check`. Messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **A CSV with one bad row imports nothing.** The bad row is named by its spreadsheet row number, and the good rows are not half-imported. → Task 5 tests.
2. **Type-specific rules hold on both entry paths.** Two correct answers on multiple choice, a multi-select with every option correct, a numeric question with no answer, and a mismatched year group and topic are all refused by the form _and_ the CSV. → Task 2 and Task 5 tests.
3. **Invalid status jumps are refused, singly and in bulk.** Draft → Published and Published → Draft are refused. Bulk changes move the valid ones and report the skipped ones. → Task 4 tests.
4. **Edits don't orphan data.** Changing a choice question's options keeps option ids by position, switching the type to numeric removes the options, and replacing an image deletes the old file. → Task 2 tests.
5. **Published questions can't be deleted**, and soft-deleted or unpublished questions never count as the bank on the dashboard. → Task 3 and Task 6 tests.

---

### Task 1: Curriculum and question schema, enums, models, factories

**Files:**

- Create:
    - `app/Enums/QuestionType.php`, `app/Enums/QuestionStatus.php`
    - `database/migrations/<ts>_create_question_bank_tables.php`
    - `app/Models/Strand.php`, `app/Models/Topic.php`, `app/Models/Question.php`, `app/Models/QuestionOption.php`, `app/Models/QuestionExamTrack.php`
    - `database/factories/StrandFactory.php`, `TopicFactory.php`, `QuestionFactory.php`, `QuestionOptionFactory.php`
    - `database/seeders/CurriculumSeeder.php`
- Modify:
    - `app/Enums/Difficulty.php`, `app/Enums/ExamTrack.php`, `app/Enums/KeyStage.php` (add `options()`)
    - `database/seeders/DatabaseSeeder.php` (call `CurriculumSeeder` first, before the early return)
- Test: `tests/Feature/QuestionBankSchemaTest.php`

**Interfaces:**

- Produces:
    - **Enums:**
        - `QuestionType` (`multiple_choice`, `multi_select`, `numeric`, `short_text`) with `label()`, `hasOptions()` and `options()`.
        - `QuestionStatus` (`draft`, `in_review`, `published`, `retired`) with `label()`, `nextStatuses(): array<QuestionStatus>`, `canMoveTo(QuestionStatus): bool`, `isDeletable(): bool` and `options()`.
        - `KeyStage::options()`, `Difficulty::options()` and `ExamTrack::options()`, each returning `array<int, array{value: string, label: string}>`.
    - **Models:**
        - `Strand`: `topics()`.
        - `Topic`: `strand()`, `questions()`; casts `key_stage: KeyStage`.
        - `Question` (SoftDeletes):
            - Casts: `type`, `status`, `difficulty` and `year_group` to their enums; `accepted_answers` to an array; `published_at` to immutable.
            - Relations: `topic()`, `options()` (ordered by `sort_order`), `examTrackRows()`, `author()`, `reviewedBy()`.
            - `#[Scope] published()` and `examTracks(): array<int, ExamTrack>`.
        - `QuestionOption`: `body`, `is_correct`, `sort_order`.
        - `QuestionExamTrack`: table `exam_track_question`; casts `exam_track: ExamTrack`.
    - **`QuestionFactory`:**
        - The default is a Draft multiple-choice question with 4 options, the first one correct.
        - States: `multiSelect()`, `numeric(float $answer = 12, float $tolerance = 0)`, `shortText(array $answers = ['twelve'])`, `published()`, `inReview()`, `retired()`.
    - **`CurriculumSeeder`**, with the public const `STRANDS` and `TOPICS`.

**Ruling:** `exam_track_question` gets its own `id` plus `unique(question_id, exam_track)` instead of a composite primary key. Eloquent can't update or delete composite-key rows cleanly. If that turns out wrong, the cost is one extra column.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest QuestionBankSchemaTest --no-interaction`:

```php
<?php

use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Strand;
use App\Models\Topic;
use Database\Seeders\CurriculumSeeder;

it('seeds four strands and topics for every key stage and strand, once', function () {
    $this->seed(CurriculumSeeder::class);
    $this->seed(CurriculumSeeder::class);

    expect(Strand::orderBy('sort_order')->pluck('slug')->all())->toBe(['number', 'algebra', 'statistics', 'geometry']);

    foreach (KeyStage::cases() as $keyStage) {
        foreach (Strand::all() as $strand) {
            expect(Topic::where('key_stage', $keyStage)->where('strand_id', $strand->id)->count())
                ->toBeGreaterThan(0, "{$keyStage->label()} {$strand->name} has no topics");
        }
    }

    expect(Topic::count())->toBe(collect(CurriculumSeeder::TOPICS)->flatten(2)->count());
});

it('allows only the agreed status transitions', function (QuestionStatus $from, QuestionStatus $to, bool $allowed) {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    'draft to review' => [QuestionStatus::Draft, QuestionStatus::InReview, true],
    'draft to published' => [QuestionStatus::Draft, QuestionStatus::Published, false],
    'draft to retired' => [QuestionStatus::Draft, QuestionStatus::Retired, false],
    'review to draft' => [QuestionStatus::InReview, QuestionStatus::Draft, true],
    'review to published' => [QuestionStatus::InReview, QuestionStatus::Published, true],
    'published to retired' => [QuestionStatus::Published, QuestionStatus::Retired, true],
    'published to draft' => [QuestionStatus::Published, QuestionStatus::Draft, false],
    'retired to draft' => [QuestionStatus::Retired, QuestionStatus::Draft, true],
    'retired to published' => [QuestionStatus::Retired, QuestionStatus::Published, false],
]);

it('only lets drafts and questions in review be deleted', function () {
    expect(collect(QuestionStatus::cases())->filter->isDeletable()->values()->all())
        ->toBe([QuestionStatus::Draft, QuestionStatus::InReview]);
});

it('builds factory questions with options and exam tracks', function () {
    $choice = Question::factory()->create();
    $multi = Question::factory()->multiSelect()->create();
    $numeric = Question::factory()->numeric(2.5, 0.1)->create();
    $choice->examTrackRows()->create(['exam_track' => ExamTrack::Sats]);

    expect($choice->status)->toBe(QuestionStatus::Draft)
        ->and($choice->type)->toBe(QuestionType::MultipleChoice)
        ->and($choice->options)->toHaveCount(4)
        ->and($choice->options->where('is_correct', true))->toHaveCount(1)
        ->and($choice->examTracks())->toBe([ExamTrack::Sats])
        ->and($multi->options->where('is_correct', true))->toHaveCount(2)
        ->and($numeric->options)->toHaveCount(0)
        ->and((float) $numeric->numeric_answer)->toBe(2.5);
});

it('scopes to published questions and leaves out soft-deleted ones', function () {
    $published = Question::factory()->published()->create();
    Question::factory()->published()->create()->delete();
    Question::factory()->inReview()->create();
    Question::factory()->retired()->create();

    expect(Question::published()->pluck('id')->all())->toBe([$published->id]);
});
```

- [ ] **Step 2: Run them to watch them fail**

Run: `php artisan test --compact tests/Feature/QuestionBankSchemaTest.php`
Expected: FAIL (`Class "App\Models\Question" not found`, `Database\Seeders\CurriculumSeeder` not found).

- [ ] **Step 3: Enums**

`app/Enums/QuestionType.php`:

```php
<?php

namespace App\Enums;

enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case MultiSelect = 'multi_select';
    case Numeric = 'numeric';
    case ShortText = 'short_text';

    public function label(): string
    {
        return match ($this) {
            self::MultipleChoice => 'Multiple choice',
            self::MultiSelect => 'Choose all that apply',
            self::Numeric => 'Number answer',
            self::ShortText => 'Short answer',
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::MultipleChoice || $this === self::MultiSelect;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
```

`app/Enums/QuestionStatus.php`:

```php
<?php

namespace App\Enums;

enum QuestionStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In review',
            self::Published => 'Published',
            self::Retired => 'Retired',
        };
    }

    /**
     * The statuses a question may move to from this one. Publishing always goes through review.
     *
     * @return array<int, self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Draft => [self::InReview],
            self::InReview => [self::Draft, self::Published],
            self::Published => [self::Retired],
            self::Retired => [self::Draft],
        };
    }

    public function canMoveTo(self $status): bool
    {
        return in_array($status, $this->nextStatuses(), true);
    }

    /**
     * Published and retired questions are kept so served quizzes keep their history.
     */
    public function isDeletable(): bool
    {
        return $this === self::Draft || $this === self::InReview;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
```

Add to each of `Difficulty`, `ExamTrack` and `KeyStage`:

```php
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
```

- [ ] **Step 4: Migration**

`php artisan make:migration create_question_bank_tables --no-interaction`, then:

```php
public function up(): void
{
    Schema::create('strands', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->timestamps();
    });

    Schema::create('topics', function (Blueprint $table) {
        $table->id();
        $table->foreignId('strand_id')->constrained()->restrictOnDelete();
        $table->string('key_stage');
        $table->string('name');
        $table->string('slug');
        $table->text('description')->nullable();
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->timestamps();
        $table->unique(['key_stage', 'slug']);
        $table->index(['key_stage', 'strand_id']);
    });

    Schema::create('questions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('topic_id')->constrained()->restrictOnDelete();
        $table->string('difficulty')->index();
        $table->unsignedTinyInteger('year_group')->nullable()->index();
        $table->string('type');
        $table->text('stem');
        $table->string('image_path')->nullable();
        $table->decimal('numeric_answer', 12, 4)->nullable();
        $table->decimal('numeric_tolerance', 12, 4)->default(0);
        $table->json('accepted_answers')->nullable();
        $table->text('explanation')->nullable();
        $table->unsignedTinyInteger('marks')->default(1);
        $table->string('status')->index();
        $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('published_at')->nullable();
        $table->unsignedInteger('times_served')->default(0);
        $table->unsignedInteger('times_correct')->default(0);
        $table->timestamps();
        $table->softDeletes();
        $table->index(['topic_id', 'difficulty', 'status']);
    });

    Schema::create('question_options', function (Blueprint $table) {
        $table->id();
        $table->foreignId('question_id')->constrained()->cascadeOnDelete();
        $table->text('body');
        $table->boolean('is_correct')->default(false);
        $table->unsignedTinyInteger('sort_order')->default(0);
        $table->timestamps();
        $table->unique(['question_id', 'sort_order']);
    });

    Schema::create('exam_track_question', function (Blueprint $table) {
        $table->id();
        $table->foreignId('question_id')->constrained()->cascadeOnDelete();
        $table->string('exam_track')->index();
        $table->unique(['question_id', 'exam_track']);
    });
}

public function down(): void
{
    Schema::dropIfExists('exam_track_question');
    Schema::dropIfExists('question_options');
    Schema::dropIfExists('questions');
    Schema::dropIfExists('topics');
    Schema::dropIfExists('strands');
}
```

- [ ] **Step 5: Models**

Follow `LessonReport`'s style: the `#[Fillable]` attribute, `casts()`, and typed relation PHPDoc.

`app/Models/Strand.php`:

```php
#[Fillable(['name', 'slug', 'sort_order'])]
class Strand extends Model
{
    /** @use HasFactory<StrandFactory> */
    use HasFactory;

    /**
     * @return HasMany<Topic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderBy('sort_order');
    }
}
```

`app/Models/Topic.php`: fillable `strand_id, key_stage, name, slug, description, sort_order`; casts `key_stage => KeyStage::class`; `strand(): BelongsTo`; `questions(): HasMany`.

`app/Models/Question.php`:

```php
/**
 * One question in the bank. Only published questions are ever served to students.
 *
 * @property int $id
 * @property int $topic_id
 * @property Difficulty $difficulty
 * @property YearGroup|null $year_group
 * @property QuestionType $type
 * @property string $stem
 * @property string|null $image_path
 * @property string|null $numeric_answer
 * @property string $numeric_tolerance
 * @property array<int, string>|null $accepted_answers
 * @property string|null $explanation
 * @property int $marks
 * @property QuestionStatus $status
 * @property int|null $author_id
 * @property int|null $reviewed_by_id
 * @property CarbonImmutable|null $published_at
 */
#[Fillable(['topic_id', 'difficulty', 'year_group', 'type', 'stem', 'image_path', 'numeric_answer', 'numeric_tolerance', 'accepted_answers', 'explanation', 'marks', 'status', 'author_id', 'reviewed_by_id', 'published_at'])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'difficulty' => Difficulty::class,
            'year_group' => YearGroup::class,
            'type' => QuestionType::class,
            'status' => QuestionStatus::class,
            'numeric_answer' => 'decimal:4',
            'numeric_tolerance' => 'decimal:4',
            'accepted_answers' => 'array',
            'marks' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo { return $this->belongsTo(Topic::class); }

    /** @return HasMany<QuestionOption, $this> */
    public function options(): HasMany { return $this->hasMany(QuestionOption::class)->orderBy('sort_order'); }

    /** @return HasMany<QuestionExamTrack, $this> */
    public function examTrackRows(): HasMany { return $this->hasMany(QuestionExamTrack::class); }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id')->withTrashed(); }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_id')->withTrashed(); }

    /**
     * @return array<int, ExamTrack>
     */
    public function examTracks(): array
    {
        return $this->examTrackRows->pluck('exam_track')->sortBy(fn (ExamTrack $track) => array_search($track, ExamTrack::cases(), true))->values()->all();
    }

    /**
     * @param  Builder<Question>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', QuestionStatus::Published);
    }
}
```

(Expand the one-line relations onto multiple lines; Pint will format them.)

`app/Models/QuestionOption.php`: fillable `question_id, body, is_correct, sort_order`; casts `is_correct => boolean`, `sort_order => integer`; `question(): BelongsTo`.

`app/Models/QuestionExamTrack.php`: `protected $table = 'exam_track_question'; public $timestamps = false;` fillable `question_id, exam_track`; casts `exam_track => ExamTrack::class`.

- [ ] **Step 6: Factories**

`StrandFactory`:

```php
public function definition(): array
{
    $name = fake()->unique()->word();

    return ['name' => ucfirst($name), 'slug' => Str::slug($name), 'sort_order' => 0];
}
```

`TopicFactory`:

```php
public function definition(): array
{
    $name = fake()->unique()->words(2, true);

    return [
        'strand_id' => Strand::factory(),
        'key_stage' => KeyStage::Ks2,
        'name' => ucfirst($name),
        'slug' => Str::slug($name),
        'sort_order' => 0,
    ];
}
```

`QuestionOptionFactory`: `question_id => Question::factory()`, `body => (string) fake()->numberBetween(1, 99)`, `is_correct => false`, `sort_order => 0`.

`QuestionFactory`:

```php
public function definition(): array
{
    return [
        'topic_id' => Topic::factory(),
        'difficulty' => Difficulty::Easy,
        'year_group' => null,
        'type' => QuestionType::MultipleChoice,
        'stem' => 'What is $'.fake()->numberBetween(2, 9).' \\times '.fake()->numberBetween(2, 9).'$?',
        'explanation' => fake()->sentence(),
        'marks' => 1,
        'status' => QuestionStatus::Draft,
        'author_id' => User::factory()->admin(),
    ];
}

/**
 * Choice questions get four options unless the test adds its own: the first is correct
 * (the first two for multi-select).
 */
public function configure(): static
{
    return $this->afterCreating(function (Question $question): void {
        if (! $question->type->hasOptions() || $question->options()->exists()) {
            return;
        }

        foreach (['12', '14', '16', '18'] as $index => $body) {
            $question->options()->create([
                'body' => $body,
                'is_correct' => $index === 0 || ($question->type === QuestionType::MultiSelect && $index === 1),
                'sort_order' => $index,
            ]);
        }
    });
}

public function multiSelect(): static
{
    return $this->state(['type' => QuestionType::MultiSelect]);
}

public function numeric(float $answer = 12, float $tolerance = 0): static
{
    return $this->state(['type' => QuestionType::Numeric, 'numeric_answer' => $answer, 'numeric_tolerance' => $tolerance]);
}

/**
 * @param  array<int, string>  $answers
 */
public function shortText(array $answers = ['twelve']): static
{
    return $this->state(['type' => QuestionType::ShortText, 'accepted_answers' => $answers]);
}

public function inReview(): static
{
    return $this->state(['status' => QuestionStatus::InReview]);
}

public function published(): static
{
    return $this->state([
        'status' => QuestionStatus::Published,
        'reviewed_by_id' => fn (array $attributes) => $attributes['author_id'],
        'published_at' => now(),
    ]);
}

public function retired(): static
{
    return $this->state(['status' => QuestionStatus::Retired, 'published_at' => now()->subMonth()]);
}
```

- [ ] **Step 7: CurriculumSeeder**

`php artisan make:seeder CurriculumSeeder --no-interaction`:

```php
<?php

namespace Database\Seeders;

use App\Enums\KeyStage;
use App\Models\Strand;
use App\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * National Curriculum strands and topics for KS2, KS3 and GCSE. Safe to run repeatedly:
 * rows are matched on slug, so names and order can be corrected by editing this list.
 */
class CurriculumSeeder extends Seeder
{
    /** @var array<string, string> slug => name */
    public const STRANDS = [
        'number' => 'Number',
        'algebra' => 'Algebra',
        'statistics' => 'Statistics',
        'geometry' => 'Geometry',
    ];

    /** @var array<string, array<string, array<int, string>>> key stage => strand slug => topic names */
    public const TOPICS = [
        'ks2' => [
            'number' => ['Place value', 'Addition & subtraction', 'Multiplication & division', 'Fractions', 'Decimals & percentages', 'Ratio & proportion'],
            'algebra' => ['Patterns & sequences', 'Formulae & equations'],
            'statistics' => ['Tables & charts', 'Averages'],
            'geometry' => ['Properties of shapes', 'Angles', 'Position & direction', 'Measurement', 'Perimeter, area & volume', 'Time & money'],
        ],
        'ks3' => [
            'number' => ['Place value & ordering', 'Four operations', 'Factors, multiples & primes', 'Fractions, decimals & percentages', 'Powers & roots', 'Standard form', 'Ratio & proportion'],
            'algebra' => ['Algebraic notation', 'Simplifying & expanding', 'Equations', 'Inequalities', 'Sequences', 'Formulae', 'Graphs'],
            'statistics' => ['Data & charts', 'Averages & spread', 'Probability'],
            'geometry' => ['Angles & polygons', 'Perimeter & area', 'Volume & surface area', 'Circles', 'Transformations', 'Constructions', "Pythagoras' theorem"],
        ],
        'ks4' => [
            'number' => ['Number', 'Fractions, decimals & percentages', 'Indices & surds', 'Standard form', 'Bounds', 'Ratio, proportion & rates of change'],
            'algebra' => ['Expressions', 'Equations', 'Quadratics', 'Simultaneous equations', 'Inequalities', 'Sequences', 'Graphs', 'Functions'],
            'statistics' => ['Probability', 'Statistics & charts'],
            'geometry' => ['Angles & polygons', 'Area & volume', 'Transformations', 'Congruence & similarity', 'Pythagoras & trigonometry', 'Circle theorems', 'Vectors'],
        ],
    ];

    public function run(): void
    {
        $strands = [];

        foreach (array_keys(self::STRANDS) as $order => $slug) {
            $strands[$slug] = Strand::updateOrCreate(['slug' => $slug], ['name' => self::STRANDS[$slug], 'sort_order' => $order]);
        }

        foreach (self::TOPICS as $keyStage => $byStrand) {
            $order = 0;

            foreach ($byStrand as $strandSlug => $names) {
                foreach ($names as $name) {
                    Topic::updateOrCreate(
                        ['key_stage' => KeyStage::from($keyStage), 'slug' => Str::slug($name)],
                        ['strand_id' => $strands[$strandSlug]->id, 'name' => $name, 'sort_order' => $order++],
                    );
                }
            }
        }
    }
}
```

In `DatabaseSeeder::run()`, add `$this->call(CurriculumSeeder::class);` as the first line, before the `admin@academy.test` early return.

- [ ] **Step 8: Migrate and run the tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/QuestionBankSchemaTest.php`
Expected: PASS, 5 tests (the transition dataset expands to 9).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/QuestionType.php app/Enums/QuestionStatus.php app/Enums/Difficulty.php app/Enums/ExamTrack.php app/Enums/KeyStage.php database/migrations/*_create_question_bank_tables.php app/Models/Strand.php app/Models/Topic.php app/Models/Question.php app/Models/QuestionOption.php app/Models/QuestionExamTrack.php database/factories/StrandFactory.php database/factories/TopicFactory.php database/factories/QuestionFactory.php database/factories/QuestionOptionFactory.php database/seeders/CurriculumSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/QuestionBankSchemaTest.php
git commit -m "feat: curriculum strands and topics, question bank schema and models"
```

---

### Task 2: Shared question input rules and the SaveQuestion action

**Files:**

- Create: `app/Support/QuestionInput.php`, `app/Actions/SaveQuestion.php`
- Test: `tests/Feature/SaveQuestionTest.php`

**Interfaces:**

- Consumes: Task 1 models and enums.
- Produces:
    - `QuestionInput::normalise(array $input): array`
    - `QuestionInput::rules(array $data): array`
    - `QuestionInput::messages(): array`
    - `QuestionInput::attributes(): array`
    - `QuestionInput::after(array $data): array<int, Closure(Validator): void>`
    - `SaveQuestion::handle(array $data, User $author, ?Question $question = null, ?UploadedFile $image = null, bool $removeImage = false): Question`
    - **The normalised data shape:**
        - Always: `topic_id`, `difficulty`, `year_group` (null when blank), `type`, `stem`, `explanation`, `marks` (default 1), `exam_tracks: list<string>`.
        - Choice types: `options: list<string>` (trailing blanks dropped) and `correct: list<int>` (0-based indexes).
        - Numeric: `numeric_answer` and `numeric_tolerance` (default 0).
        - Short text: `accepted_answers: list<string>`, which also accepts a newline-separated string.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest SaveQuestionTest --no-interaction`:

```php
<?php

use App\Actions\SaveQuestion;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Support\QuestionInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->topic = Topic::factory()->create(['key_stage' => KeyStage::Ks2]);
});

function questionInput(array $overrides = []): array
{
    return [
        'topic_id' => test()->topic->id,
        'difficulty' => 'easy',
        'year_group' => '5',
        'type' => 'multiple_choice',
        'stem' => '  What is $\frac{1}{2}$ of 8?  ',
        'options' => ['4', '2', '8', ''],
        'correct' => ['0'],
        'numeric_answer' => '99',
        'accepted_answers' => "four\n",
        'explanation' => '',
        'marks' => '',
        'exam_tracks' => ['sats'],
        ...$overrides,
    ];
}

function validateQuestion(array $input): \Illuminate\Validation\Validator
{
    $data = QuestionInput::normalise($input);

    return tap(Validator::make($data, QuestionInput::rules($data), QuestionInput::messages(), QuestionInput::attributes())
        ->after(QuestionInput::after($data)), fn ($validator) => $validator->passes());
}

it('normalises input and keeps only the fields the type uses', function () {
    $data = QuestionInput::normalise(questionInput());

    expect($data)->toMatchArray([
        'stem' => 'What is $\frac{1}{2}$ of 8?',
        'year_group' => '5',
        'explanation' => null,
        'marks' => 1,
        'options' => ['4', '2', '8'],
        'correct' => [0],
        'exam_tracks' => ['sats'],
    ])->not->toHaveKeys(['numeric_answer', 'accepted_answers']);

    expect(QuestionInput::normalise(questionInput(['type' => 'short_text'])))
        ->toHaveKey('accepted_answers', ['four'])
        ->not->toHaveKeys(['options', 'correct', 'numeric_answer']);
});

it('accepts a valid question of each type', function (array $overrides) {
    expect(validateQuestion(questionInput($overrides))->errors()->all())->toBe([]);
})->with([
    'multiple choice' => [[]],
    'multi-select' => [['type' => 'multi_select', 'correct' => ['0', '2']]],
    'numeric' => [['type' => 'numeric', 'numeric_answer' => '0.75', 'numeric_tolerance' => '0.01']],
    'short text' => [['type' => 'short_text', 'accepted_answers' => "four\nFour\n4"]],
]);

it('refuses type-specific mistakes', function (array $overrides, string $field, string $message) {
    expect(validateQuestion(questionInput($overrides))->errors()->first($field))->toBe($message);
})->with([
    'two correct on multiple choice' => [['correct' => ['0', '1']], 'correct', 'A multiple-choice question has exactly one correct answer.'],
    'no correct answer' => [['correct' => []], 'correct', 'Mark the correct answer.'],
    'correct answer out of range' => [['correct' => ['5']], 'correct.0', 'The correct answer must be one of the options.'],
    'one option' => [['options' => ['4']], 'options', 'Add at least two options.'],
    'blank option in the middle' => [['options' => ['4', '', '8']], 'options.1', 'Fill in this option or remove it.'],
    'every option correct on multi-select' => [['type' => 'multi_select', 'options' => ['4', '8'], 'correct' => ['0', '1']], 'correct', 'At least one option must be wrong.'],
    'numeric with no answer' => [['type' => 'numeric', 'numeric_answer' => ''], 'numeric_answer', 'Enter the correct number.'],
    'negative tolerance' => [['type' => 'numeric', 'numeric_tolerance' => '-1'], 'numeric_tolerance', 'The tolerance field must be at least 0.'],
    'short text with no answers' => [['type' => 'short_text', 'accepted_answers' => "\n"], 'accepted_answers', 'Add at least one accepted answer.'],
    'year group outside the key stage' => [['year_group' => '8'], 'year_group', "Year 8 isn't in KS2. Choose a KS2 year or leave it blank."],
    'unknown exam track' => [['exam_tracks' => ['a-level']], 'exam_tracks.0', 'The selected exam track is invalid.'],
    'no stem' => [['stem' => '   '], 'stem', 'The question field is required.'],
]);

it('creates a draft with its options and exam tracks', function () {
    $data = QuestionInput::normalise(questionInput(['exam_tracks' => ['sats', 'eleven_plus']]));
    $question = app(SaveQuestion::class)->handle($data, $this->admin);

    expect($question->status)->toBe(QuestionStatus::Draft)
        ->and($question->author_id)->toBe($this->admin->id)
        ->and($question->options->pluck('body')->all())->toBe(['4', '2', '8'])
        ->and($question->options->pluck('is_correct')->all())->toBe([true, false, false])
        ->and($question->examTracks())->toBe([ExamTrack::ElevenPlus, ExamTrack::Sats])
        ->and($question->numeric_answer)->toBeNull();
});

it('keeps option ids by position when editing and removes options when the type changes', function () {
    $save = app(SaveQuestion::class);
    $question = $save->handle(QuestionInput::normalise(questionInput()), $this->admin);
    $firstId = $question->options->first()->id;

    $question = $save->handle(QuestionInput::normalise(questionInput(['options' => ['5', '6'], 'correct' => ['1'], 'exam_tracks' => []])), $this->admin, $question);

    expect($question->fresh()->options->pluck('body')->all())->toBe(['5', '6'])
        ->and($question->fresh()->options->first()->id)->toBe($firstId)
        ->and($question->fresh()->examTracks())->toBe([]);

    $save->handle(QuestionInput::normalise(questionInput(['type' => 'numeric', 'numeric_answer' => '4'])), $this->admin, $question);

    expect($question->fresh()->type)->toBe(QuestionType::Numeric)
        ->and($question->fresh()->options)->toHaveCount(0)
        ->and((float) $question->fresh()->numeric_answer)->toBe(4.0);
});

it('stores, replaces and removes the diagram', function () {
    Storage::fake('public');
    $save = app(SaveQuestion::class);
    $data = QuestionInput::normalise(questionInput());

    $question = $save->handle($data, $this->admin, image: UploadedFile::fake()->image('first.png'));
    $first = $question->image_path;
    Storage::disk('public')->assertExists($first);

    $question = $save->handle($data, $this->admin, $question, UploadedFile::fake()->image('second.png'));
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($question->image_path);

    $second = $question->image_path;
    $question = $save->handle($data, $this->admin, $question, removeImage: true);
    expect($question->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($second);
});
```

- [ ] **Step 2: Run them to watch them fail**

Run: `php artisan test --compact tests/Feature/SaveQuestionTest.php`
Expected: FAIL (`Class "App\Support\QuestionInput" not found`).

- [ ] **Step 3: QuestionInput**

```php
<?php

namespace App\Support;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\QuestionType;
use App\Enums\YearGroup;
use App\Models\Topic;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The single definition of a valid question, shared by the admin form and the CSV import.
 */
final class QuestionInput
{
    /**
     * Trim the raw input and keep only the answer fields the question's type uses.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalise(array $input): array
    {
        $type = QuestionType::tryFrom((string) ($input['type'] ?? ''));

        $data = [
            'topic_id' => $input['topic_id'] ?? null,
            'difficulty' => $input['difficulty'] ?? null,
            'year_group' => self::blankToNull($input['year_group'] ?? null),
            'type' => $input['type'] ?? null,
            'stem' => self::trimmed($input['stem'] ?? null),
            'explanation' => self::blankToNull(self::trimmed($input['explanation'] ?? null)),
            'marks' => self::blankToNull($input['marks'] ?? null) ?? 1,
            'exam_tracks' => array_values(array_filter((array) ($input['exam_tracks'] ?? []), fn (mixed $track): bool => filled($track))),
        ];

        if ($type?->hasOptions()) {
            $options = array_map(self::trimmed(...), array_values((array) ($input['options'] ?? [])));

            while ($options !== [] && in_array(end($options), ['', null], true)) {
                array_pop($options);
            }

            $data['options'] = $options;
            $data['correct'] = array_values(array_unique(array_map('intval', array_filter((array) ($input['correct'] ?? []), 'is_numeric'))));
        }

        if ($type === QuestionType::Numeric) {
            $data['numeric_answer'] = self::blankToNull($input['numeric_answer'] ?? null);
            $data['numeric_tolerance'] = self::blankToNull($input['numeric_tolerance'] ?? null) ?? 0;
        }

        if ($type === QuestionType::ShortText) {
            $answers = $input['accepted_answers'] ?? [];
            $answers = is_string($answers) ? preg_split('/\R/', $answers) : (array) $answers;
            $data['accepted_answers'] = array_values(array_filter(array_map(self::trimmed(...), $answers), fn (mixed $answer): bool => filled($answer)));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  normalised input
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $data): array
    {
        $lastOption = max(0, count($data['options'] ?? []) - 1);

        return [
            'topic_id' => ['required', 'integer', Rule::exists('topics', 'id')],
            'difficulty' => ['required', Rule::enum(Difficulty::class)],
            'year_group' => ['nullable', Rule::enum(YearGroup::class)],
            'type' => ['required', Rule::enum(QuestionType::class)],
            'stem' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'marks' => ['required', 'integer', 'min:1', 'max:5'],
            'exam_tracks' => ['array'],
            'exam_tracks.*' => ['distinct', Rule::enum(ExamTrack::class)],
            'options' => ['sometimes', 'required', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:500'],
            'correct' => ['sometimes', 'required', 'array'],
            'correct.*' => ['integer', 'min:0', "max:{$lastOption}"],
            'numeric_answer' => ['sometimes', 'required', 'numeric', 'between:-1000000000,1000000000'],
            'numeric_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'accepted_answers' => ['sometimes', 'required', 'array', 'max:10'],
            'accepted_answers.*' => ['string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'topic_id.required' => 'Choose a topic.',
            'topic_id.exists' => 'Choose a topic from the list.',
            'options.required' => 'Add at least two options.',
            'options.min' => 'Add at least two options.',
            'options.max' => 'Use at most six options.',
            'options.*.required' => 'Fill in this option or remove it.',
            'correct.required' => 'Mark the correct answer.',
            'correct.*.min' => 'The correct answer must be one of the options.',
            'correct.*.max' => 'The correct answer must be one of the options.',
            'numeric_answer.required' => 'Enter the correct number.',
            'accepted_answers.required' => 'Add at least one accepted answer.',
            'accepted_answers.max' => 'Use at most ten accepted answers.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'topic_id' => 'topic',
            'year_group' => 'year group',
            'stem' => 'question',
            'options.*' => 'option',
            'numeric_answer' => 'answer',
            'numeric_tolerance' => 'tolerance',
            'accepted_answers.*' => 'accepted answer',
            'exam_tracks.*' => 'exam track',
        ];
    }

    /**
     * Cross-field checks that only make sense once the basic rules pass.
     *
     * @param  array<string, mixed>  $data  normalised input
     * @return array<int, Closure(Validator): void>
     */
    public static function after(array $data): array
    {
        return [function (Validator $validator) use ($data): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = QuestionType::from($data['type']);
            $correct = count($data['correct'] ?? []);

            if ($type === QuestionType::MultipleChoice && $correct > 1) {
                $validator->errors()->add('correct', 'A multiple-choice question has exactly one correct answer.');
            }

            if ($type === QuestionType::MultiSelect && $correct === count($data['options'])) {
                $validator->errors()->add('correct', 'At least one option must be wrong.');
            }

            $yearGroup = $data['year_group'] === null ? null : YearGroup::from((int) $data['year_group']);
            $keyStage = Topic::find($data['topic_id'])->key_stage;

            if ($yearGroup && $yearGroup->keyStage() !== $keyStage) {
                $validator->errors()->add('year_group', "{$yearGroup->label()} isn't in {$keyStage->label()}. Choose a {$keyStage->label()} year or leave it blank.");
            }
        }];
    }

    private static function trimmed(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private static function blankToNull(mixed $value): mixed
    {
        return blank($value) ? null : $value;
    }
}
```

- [ ] **Step 4: SaveQuestion**

```php
<?php

namespace App\Actions;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The only writer of a question, its options and its exam tracks. New questions start as drafts;
 * saving never changes an existing question's status.
 */
class SaveQuestion
{
    /**
     * @param  array<string, mixed>  $data  validated QuestionInput data
     */
    public function handle(array $data, User $author, ?Question $question = null, ?UploadedFile $image = null, bool $removeImage = false): Question
    {
        $question ??= new Question(['status' => QuestionStatus::Draft, 'author_id' => $author->id]);
        $previousImage = $question->image_path;
        $type = QuestionType::from($data['type']);

        DB::transaction(function () use ($question, $data, $type, $image, $removeImage): void {
            $question->fill([
                'topic_id' => $data['topic_id'],
                'difficulty' => $data['difficulty'],
                'year_group' => $data['year_group'],
                'type' => $type,
                'stem' => $data['stem'],
                'explanation' => $data['explanation'],
                'marks' => $data['marks'],
                'numeric_answer' => $type === QuestionType::Numeric ? $data['numeric_answer'] : null,
                'numeric_tolerance' => $type === QuestionType::Numeric ? $data['numeric_tolerance'] : 0,
                'accepted_answers' => $type === QuestionType::ShortText ? $data['accepted_answers'] : null,
            ]);

            if ($image) {
                $question->image_path = $image->store('questions', 'public');
            } elseif ($removeImage) {
                $question->image_path = null;
            }

            $question->save();

            $options = $type->hasOptions() ? $data['options'] : [];

            foreach ($options as $index => $body) {
                $question->options()->updateOrCreate(
                    ['sort_order' => $index],
                    ['body' => $body, 'is_correct' => in_array($index, $data['correct'], true)],
                );
            }

            $question->options()->where('sort_order', '>=', count($options))->delete();

            $question->examTrackRows()->delete();

            foreach (array_unique($data['exam_tracks']) as $track) {
                $question->examTrackRows()->create(['exam_track' => $track]);
            }
        });

        if ($previousImage && $previousImage !== $question->image_path) {
            Storage::disk('public')->delete($previousImage);
        }

        return $question->load(['options', 'examTrackRows']);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/SaveQuestionTest.php`
Expected: PASS. If a framework default message differs from a dataset row (e.g. the tolerance or exam track wording), fix the _dataset_ to the framework's exact wording only when it's the stock message; the custom messages above are the contract.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/QuestionInput.php app/Actions/SaveQuestion.php tests/Feature/SaveQuestionTest.php
git commit -m "feat: shared question validation and the SaveQuestion action"
```

---

### Task 3: Admin question bank (list, create, edit, delete) with KaTeX preview

**Files:**

- Create:
    - `app/Http/Controllers/Admin/QuestionController.php`
    - `app/Http/Requests/Admin/QuestionRequest.php`
    - `resources/js/components/math-text.tsx`, `resources/js/components/question-status-badge.tsx`, `resources/js/components/question-fields.tsx`
    - `resources/js/pages/admin/questions/index.tsx`, `create.tsx`, `edit.tsx`
- Modify:
    - `routes/web.php`
    - `resources/js/components/app-sidebar.tsx` (a "Question bank" item)
    - `resources/js/components/icon.tsx` (a `question` icon)
    - `package.json` (`katex`)
- Test: `tests/Feature/Admin/QuestionBankTest.php`

**Interfaces:**

- Consumes: `QuestionInput`, `SaveQuestion`, and the Task 1 enums and models.
- **Routes** (inside the admin group), in this order:
    - `Route::put('questions/bulk-status', …)` (Task 4)
    - `Route::get('questions/import', …)`, `questions/import/template` and `POST questions/import` (Task 5)
    - `Route::resource('questions', QuestionController::class)->except(['show'])`
- Produces these page props:
    - **`admin/questions/index`:**
        - `questions: Paginated<Row>`, where a Row has `id, stem, topic, strand, keyStage, yearGroup, difficulty, type, status, statusLabel, examTracks: string[], updatedAt, canDelete, nextStatuses: Option[]`.
        - `filters: {search, key_stage, strand, topic, difficulty, type, status, exam_track}` (strings or null).
        - `counts: Record<status, number>`.
        - `options` (see `formOptions()`).
    - **`admin/questions/create`:** `options`.
    - **`admin/questions/edit`:** `options` plus `question: {id, topicId, difficulty, yearGroup, type, stem, options: string[], correct: number[], numericAnswer, numericTolerance, acceptedAnswers: string[], explanation, marks, examTracks: string[], imageUrl, status, statusLabel, nextStatuses: Option[], author, reviewedBy, publishedAt, canDelete}`.
    - **`options`:** `{keyStages, strands: Option[] (value = slug), topics: {value: id, label, keyStage, strand}[], difficulties, types, statuses, examTracks, yearGroups: {value, label, keyStage}[]}`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest Admin/QuestionBankTest --no-interaction` (Pest puts it under `tests/Feature/Admin/`):

```php
<?php

use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\Strand;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->strand = Strand::factory()->create(['name' => 'Number', 'slug' => 'number']);
    $this->topic = Topic::factory()->for($this->strand)->create(['key_stage' => KeyStage::Ks2, 'name' => 'Fractions']);
});

function questionPayload(array $overrides = []): array
{
    return [
        'topic_id' => test()->topic->id,
        'difficulty' => 'intermediate',
        'year_group' => 5,
        'type' => 'multiple_choice',
        'stem' => 'What is $\frac{1}{4}$ of 12?',
        'options' => ['3', '4', '6'],
        'correct' => [0],
        'explanation' => 'Divide 12 into 4 equal groups.',
        'marks' => 1,
        'exam_tracks' => ['sats'],
        ...$overrides,
    ];
}

it('is only for admins', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('admin.questions.index'))
        ->assertForbidden();
})->with(['tutor', 'guardian', 'student']);

it('lists questions with status counts and filters', function () {
    Question::factory()->for($this->topic)->published()->create(['stem' => 'Halve 18']);
    Question::factory()->for($this->topic)->create(['stem' => 'Double 7']);
    Question::factory()->create(['stem' => 'Elsewhere']);

    $this->actingAs($this->admin)->get(route('admin.questions.index', ['status' => 'published', 'search' => 'HALVE']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/questions/index')
            ->has('questions.data', 1)
            ->where('questions.data.0.stem', 'Halve 18')
            ->where('questions.data.0.topic', 'Fractions')
            ->where('questions.data.0.keyStage', 'KS2')
            ->where('questions.data.0.canDelete', false)
            ->where('questions.data.0.nextStatuses.0.value', 'retired')
            ->where('counts.published', 1)
            ->where('counts.draft', 2)
            ->where('filters.status', 'published')
            ->has('options.topics', 2)
            ->where('options.strands.0.value', 'number'));

    $this->actingAs($this->admin)->get(route('admin.questions.index', ['topic' => $this->topic->id]))
        ->assertInertia(fn (Assert $page) => $page->has('questions.data', 2));
});

it('creates a draft question from the form', function () {
    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload())
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $question = Question::sole();

    expect($question->status)->toBe(QuestionStatus::Draft)
        ->and($question->author_id)->toBe($this->admin->id)
        ->and($question->options->pluck('body')->all())->toBe(['3', '4', '6']);
});

it('shows form errors with the shared rules', function () {
    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload(['correct' => [0, 1], 'year_group' => 9]))
        ->assertSessionHasErrors(['year_group']);

    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload(['correct' => [0, 1]]))
        ->assertSessionHasErrors(['correct' => 'A multiple-choice question has exactly one correct answer.']);

    expect(Question::count())->toBe(0);
});

it('refuses SVG diagrams', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)->post(route('admin.questions.store'), questionPayload([
        'image' => UploadedFile::fake()->createWithContent('shape.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
    ]))->assertSessionHasErrors(['image']);
});

it('edits a question and keeps its status', function () {
    Storage::fake('public');
    $question = Question::factory()->for($this->topic)->published()->create();

    $this->actingAs($this->admin)->get(route('admin.questions.edit', $question))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/questions/edit')
            ->where('question.id', $question->id)
            ->where('question.options', ['12', '14', '16', '18'])
            ->where('question.correct', [0])
            ->where('question.status', 'published')
            ->where('question.canDelete', false));

    $this->actingAs($this->admin)
        ->post(route('admin.questions.update', $question), [
            ...questionPayload(['type' => 'numeric', 'numeric_answer' => '3']),
            '_method' => 'put',
            'image' => UploadedFile::fake()->image('diagram.png'),
        ])
        ->assertRedirect(route('admin.questions.edit', $question));

    expect($question->fresh()->status)->toBe(QuestionStatus::Published)
        ->and($question->fresh()->options)->toHaveCount(0)
        ->and($question->fresh()->image_path)->not->toBeNull();
});

it('deletes drafts but not published questions', function () {
    $draft = Question::factory()->create();
    $published = Question::factory()->published()->create();

    $this->actingAs($this->admin)->delete(route('admin.questions.destroy', $draft))
        ->assertRedirect(route('admin.questions.index'));
    $this->actingAs($this->admin)->delete(route('admin.questions.destroy', $published))
        ->assertInertiaFlash('toast.type', 'error');

    expect($draft->fresh()->trashed())->toBeTrue()
        ->and($published->fresh()->trashed())->toBeFalse();
});
```

- [ ] **Step 2: Run them to watch them fail**

Run: `php artisan test --compact tests/Feature/Admin/QuestionBankTest.php`
Expected: FAIL (`Route [admin.questions.index] not defined`).

- [ ] **Step 3: Form request**

```php
<?php

namespace App\Http\Requests\Admin;

use App\Support\QuestionInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QuestionRequest extends FormRequest
{
    /**
     * Route middleware (`role:admin`) already restricts who can reach this.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace([
            ...QuestionInput::normalise($this->all()),
            'remove_image' => $this->boolean('remove_image'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...QuestionInput::rules($this->all()),
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_image' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return QuestionInput::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...QuestionInput::attributes(), 'image' => 'diagram'];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return QuestionInput::after($this->all());
    }
}
```

`replace()` only swaps the input bag. The uploaded `image` stays in the files bag and still reaches validation.

- [ ] **Step 4: Controller and routes**

```php
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
```

In `routes/web.php`, inside the admin group, after the lateness routes (Task 4 and Task 5 add their routes **above** the resource line):

```php
    Route::resource('questions', QuestionController::class)->except(['show']);
```

with `use App\Http\Controllers\Admin\QuestionController;` at the top. Then run `php artisan storage:link` once locally (images are served from `public/storage`).

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Admin/QuestionBankTest.php`
Expected: PASS (the backend is complete; the pages come next, and the Inertia tests check component names only when the files exist, so create them before re-running in Step 8).

- [ ] **Step 6: KaTeX and the MathText component**

Run: `npm install katex@^0.16` (approved, D3). If `tsc` can't find types, also run `npm install -D @types/katex`.

`resources/js/components/math-text.tsx`:

```tsx
import katex from 'katex';
import 'katex/dist/katex.min.css';
import { Fragment, useMemo } from 'react';
import { cn } from '@/lib/utils';

type Segment =
    | { kind: 'text'; value: string }
    | { kind: 'math'; value: string; display: boolean };

/**
 * Splits text on $…$ (inline) and $$…$$ (display) maths. "\$" is a literal dollar sign,
 * and an unclosed "$" is kept as text.
 */
export function splitMath(text: string): Segment[] {
    const segments: Segment[] = [];
    let buffer = '';
    let index = 0;

    while (index < text.length) {
        if (text.startsWith('\\$', index)) {
            buffer += '$';
            index += 2;
            continue;
        }

        if (text[index] === '$') {
            const display = text.startsWith('$$', index);
            const delimiter = display ? '$$' : '$';
            const close = text.indexOf(delimiter, index + delimiter.length);

            if (close > index + delimiter.length) {
                if (buffer) {
                    segments.push({ kind: 'text', value: buffer });
                    buffer = '';
                }

                segments.push({
                    kind: 'math',
                    value: text.slice(index + delimiter.length, close),
                    display,
                });
                index = close + delimiter.length;
                continue;
            }
        }

        buffer += text[index];
        index += 1;
    }

    if (buffer) {
        segments.push({ kind: 'text', value: buffer });
    }

    return segments;
}

type MathTextProps = {
    text: string;
    className?: string;
};

/** Plain text with KaTeX maths. Line breaks in the text are kept. */
export default function MathText({ text, className }: MathTextProps) {
    const segments = useMemo(() => splitMath(text), [text]);

    return (
        <span className={cn('whitespace-pre-line', className)}>
            {segments.map((segment, index) =>
                segment.kind === 'text' ? (
                    <Fragment key={index}>{segment.value}</Fragment>
                ) : (
                    <span
                        key={index}
                        className={cn(segment.display && 'my-2 block')}
                        // KaTeX escapes its input; trust is off, so \href and \url are not rendered.
                        dangerouslySetInnerHTML={{
                            __html: katex.renderToString(segment.value, {
                                displayMode: segment.display,
                                throwOnError: false,
                                trust: false,
                                output: 'htmlAndMathml',
                            }),
                        }}
                    />
                ),
            )}
        </span>
    );
}
```

`resources/js/components/question-status-badge.tsx`:

```tsx
import { Badge } from '@/components/ui/badge';
import type { BadgeTone } from '@/components/ui/badge';

export type QuestionStatus = 'draft' | 'in_review' | 'published' | 'retired';

const tones: Record<QuestionStatus, BadgeTone> = {
    draft: 'neutral',
    in_review: 'gold',
    published: 'present',
    retired: 'excused',
};

export default function QuestionStatusBadge({
    status,
    label,
}: {
    status: QuestionStatus;
    label: string;
}) {
    return <Badge tone={tones[status]}>{label}</Badge>;
}
```

Add a `question` icon to `icon.tsx`: append `'question'` to the `IconName` union and `question: (<><circle cx="12" cy="12" r="9" /><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01" /></>)` to `paths`. In `app-sidebar.tsx`, add `{ label: 'Question bank', icon: 'question', href: adminQuestionsIndex }` after Class reports, importing `index as adminQuestionsIndex` from `@/routes/admin/questions`.

- [ ] **Step 7: Shared fields and the pages**

`resources/js/components/question-fields.tsx`: the shared form body for create and edit. It is controlled locally, so the preview updates as the admin types. Every input has a `name`, so `<Form>` serialises it.

```tsx
import { useState } from 'react';
import FieldError from '@/components/field-error';
import MathText from '@/components/math-text';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import type { Option } from '@/types';

export type QuestionFormOptions = {
    keyStages: Option[];
    strands: Option[];
    topics: {
        value: number;
        label: string;
        keyStage: string;
        strand: string;
    }[];
    difficulties: Option[];
    types: Option[];
    statuses: Option[];
    examTracks: Option[];
    yearGroups: { value: number; label: string; keyStage: string }[];
};

export type QuestionFormValues = {
    topicId: number | null;
    difficulty: string;
    yearGroup: number | null;
    type: string;
    stem: string;
    options: string[];
    correct: number[];
    numericAnswer: string | null;
    numericTolerance: string;
    acceptedAnswers: string[];
    explanation: string | null;
    marks: number;
    examTracks: string[];
    imageUrl: string | null;
};

export const emptyQuestion: QuestionFormValues = {
    topicId: null,
    difficulty: 'easy',
    yearGroup: null,
    type: 'multiple_choice',
    stem: '',
    options: ['', '', '', ''],
    correct: [],
    numericAnswer: null,
    numericTolerance: '0',
    acceptedAnswers: [],
    explanation: null,
    marks: 1,
    examTracks: [],
    imageUrl: null,
};

const letters = 'ABCDEF';

type QuestionFieldsProps = {
    initial: QuestionFormValues;
    options: QuestionFormOptions;
    errors: Partial<Record<string, string>>;
};

export default function QuestionFields({
    initial,
    options,
    errors,
}: QuestionFieldsProps) {
    const initialTopic = options.topics.find(
        (topic) => topic.value === initial.topicId,
    );
    const [keyStage, setKeyStage] = useState(
        initialTopic?.keyStage ?? options.keyStages[0]?.value.toString() ?? '',
    );
    const [strand, setStrand] = useState(
        initialTopic?.strand ?? options.strands[0]?.value.toString() ?? '',
    );
    const [type, setType] = useState(initial.type);
    const [stem, setStem] = useState(initial.stem);
    const [choices, setChoices] = useState(
        initial.options.length > 0 ? initial.options : ['', '', '', ''],
    );
    const [correct, setCorrect] = useState<number[]>(initial.correct);
    const [explanation, setExplanation] = useState(initial.explanation ?? '');
    const [removeImage, setRemoveImage] = useState(false);

    const hasOptions = type === 'multiple_choice' || type === 'multi_select';
    const topics = options.topics.filter(
        (topic) => topic.keyStage === keyStage && topic.strand === strand,
    );
    const yearGroups = options.yearGroups.filter(
        (year) => year.keyStage === keyStage,
    );

    const toggleCorrect = (index: number) =>
        setCorrect((current) =>
            type === 'multiple_choice'
                ? [index]
                : current.includes(index)
                  ? current.filter((value) => value !== index)
                  : [...current, index],
        );

    const removeChoice = (index: number) => {
        setChoices((current) => current.filter((_, i) => i !== index));
        setCorrect((current) =>
            current
                .filter((value) => value !== index)
                .map((value) => (value > index ? value - 1 : value)),
        );
    };

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:items-start">
            <div className="flex flex-col gap-4">
                <Card className="grid gap-4 p-card sm:grid-cols-2">
                    {/* Key stage, Strand (local filters for the topic list), Topic (name="topic_id"),
                        Year group (name="year_group", blank = whole key stage), Difficulty (name="difficulty"),
                        Type (name="type", onChange → setType), Marks (name="marks", type=number 1–5).
                        Each: Label + Select/Input + FieldError id="<field>-error" + aria-describedby + aria-invalid. */}
                </Card>
                <Card className="flex flex-col gap-4 p-card">
                    {/* Question: <textarea name="stem" rows={4} value={stem} onChange={…}> styled like Input
                        (rounded-md border border-input bg-surface px-3.5 py-2.5 text-sm sm:text-[15px]).
                        Hint under it: "Use $…$ for maths, e.g. $\frac{3}{4}$ or $2^3$. Type \$ for a dollar sign."
                        Diagram: <input type="file" name="image" accept="image/png,image/jpeg,image/webp">;
                        when initial.imageUrl, show the image and a "Remove diagram" checkbox
                        (name="remove_image" value="1", checked={removeImage}). FieldError for stem and image. */}
                </Card>
                {hasOptions && (
                    <Card className="flex flex-col gap-3 p-card">
                        {/* Heading "Answer options" + hint ("Tick the correct answer" / "Tick every correct answer").
                            For each choice i: letter, Input name="options[]" value={choices[i]} onChange,
                            a radio (multiple_choice) or checkbox (multi_select) name="correct[]" value={i}
                            checked={correct.includes(i)} onChange={() => toggleCorrect(i)} with a visible label
                            "Correct", a ghost "Remove" button when choices.length > 2, FieldError options.{i}.
                            Below: "Add option" (outline, sm) when choices.length < 6. FieldError options / correct. */}
                    </Card>
                )}
                {type === 'numeric' && (
                    <Card className="grid gap-4 p-card sm:grid-cols-2">
                        {/* Answer: Input name="numeric_answer" inputMode="decimal" defaultValue={initial.numericAnswer ?? ''}.
                            Tolerance: Input name="numeric_tolerance" defaultValue={initial.numericTolerance},
                            hint "Answers within this much of the correct number are marked right." */}
                    </Card>
                )}
                {type === 'short_text' && (
                    <Card className="flex flex-col gap-2 p-card">
                        {/* Accepted answers: <textarea name="accepted_answers" rows={3}
                            defaultValue={initial.acceptedAnswers.join('\n')}>, hint "One per line. Capital letters don't matter." */}
                    </Card>
                )}
                <Card className="flex flex-col gap-4 p-card">
                    {/* Worked explanation: <textarea name="explanation" value={explanation} onChange>.
                        Exam tracks: a fieldset with a legend and a checkbox per options.examTracks,
                        name="exam_tracks[]" value={track.value} defaultChecked={initial.examTracks.includes(track.value)}. */}
                </Card>
            </div>
            <Card className="flex flex-col gap-3 p-card lg:sticky lg:top-6">
                <p className="text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs">
                    Student preview
                </p>
                <p className="text-base font-semibold sm:text-lg">
                    {stem.trim() ? (
                        <MathText text={stem} />
                    ) : (
                        <span className="text-muted-foreground">
                            Your question appears here.
                        </span>
                    )}
                </p>
                {hasOptions && (
                    <ul className="flex flex-col gap-2">
                        {choices.map((choice, index) => (
                            <li
                                key={index}
                                className="flex items-start gap-2 rounded-md border px-3 py-2 text-sm"
                            >
                                <span className="font-semibold">
                                    {letters[index]}
                                </span>
                                <MathText text={choice || '…'} />
                            </li>
                        ))}
                    </ul>
                )}
                {explanation.trim() && (
                    <div className="rounded-md bg-surface-muted p-3 text-sm">
                        <p className="mb-1 font-semibold">Explanation</p>
                        <MathText text={explanation} />
                    </div>
                )}
            </Card>
        </div>
    );
}
```

The comment blocks above describe markup that follows the existing `class-group-fields.tsx` patterns exactly. Write them out as JSX when implementing; don't leave the comments in. Error keys are dotted (`errors['options.1']`), per the Inertia docs.

`resources/js/pages/admin/questions/create.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import QuestionFields, { emptyQuestion } from '@/components/question-fields';
import type { QuestionFormOptions } from '@/components/question-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/admin/questions';

export default function CreateQuestion({
    options,
}: {
    options: QuestionFormOptions;
}) {
    return (
        <AppLayout title="New question" subtitle="Question bank">
            <Form {...store.form()} className="flex flex-col gap-4">
                {({ errors, processing }) => (
                    <>
                        <QuestionFields
                            initial={emptyQuestion}
                            options={options}
                            errors={errors}
                        />
                        <div>
                            <Button type="submit" disabled={processing}>
                                Save as draft
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AppLayout>
    );
}
```

`edit.tsx`:

- **Header strip:** a `QuestionStatusBadge`, then "Written by {author}" and "· Published {publishedAt} by {reviewedBy}" when set. Next to it, one button per `nextStatuses` entry. Each is a `<Form {...statusUpdate.form(question.id)}>` with a hidden `status` input (Task 4 adds the route; until then, render nothing for this).
- **Main form:** `<Form action={update.url(question.id)} method="post">` with a hidden `<input type="hidden" name="_method" value="put" />` (multipart method spoofing), then `QuestionFields` with `initial={question}` and Save.
- **Delete:** shown when `canDelete`, as a `<Form {...destroy.form(question.id)}>` danger button with `onBefore={() => confirm('Delete this question?')}`.

`index.tsx` follows `admin/users/index.tsx`:

- **Status tabs:** "All", then each status with its `counts` number. Each tab is a `Link` to `index({ query: { ...filters, status } })`.
- **Filter form:** search, key stage, strand, topic (filtered by the chosen key stage and strand), difficulty, type and exam track.
- **Buttons:** an "Add question" link and an "Upload CSV" link (Task 5; leave it out until then).
- **Table columns:** a checkbox (Task 4), the question rendered with `<MathText>` and clamped with `line-clamp-2`, topic · key stage · year, a `DifficultyBadge`, type, `QuestionStatusBadge`, updated date, and an Edit link.
- **Empty state:** "No questions match these filters." When there are no questions at all, it reads "The bank is empty. Add a question or upload a CSV."
- **Pagination:** prev/next links, as on the users page.

- [ ] **Step 8: Checks, tests and a screenshot**

Run: `npm run check:fix && npm run types:check && npm run build && php artisan test --compact tests/Feature/Admin/QuestionBankTest.php`
Expected: no lint or type errors, and PASS. Then take screenshots of `/admin/questions/create` at 360 and 1280 (scratchpad `shot.mjs` with `a.jar`): there's no horizontal scroll, and the preview renders `\frac` as a fraction.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/QuestionController.php app/Http/Requests/Admin/QuestionRequest.php routes/web.php package.json package-lock.json resources/js/components/math-text.tsx resources/js/components/question-status-badge.tsx resources/js/components/question-fields.tsx resources/js/components/icon.tsx resources/js/components/app-sidebar.tsx resources/js/pages/admin/questions tests/Feature/Admin/QuestionBankTest.php
git commit -m "feat: admin question bank with KaTeX preview"
```

---

### Task 4: Review workflow, single and bulk

**Files:**

- Create: `app/Actions/ChangeQuestionStatus.php`, `app/Http/Controllers/Admin/QuestionStatusController.php`
- Modify: `routes/web.php`, `resources/js/pages/admin/questions/edit.tsx` (status buttons), `resources/js/pages/admin/questions/index.tsx` (row checkboxes and a bulk bar)
- Test: `tests/Feature/Admin/QuestionStatusTest.php`

**Interfaces:**

- `ChangeQuestionStatus::handle(Question $question, QuestionStatus $status, User $admin): bool`
- **Routes:**
    - `PUT admin/questions/bulk-status` → `bulk`, named `admin.questions.bulk-status`, with `ids[]` (max 100) and `status`. It is declared **before** the resource.
    - `PUT admin/questions/{question}/status` → `update`, named `admin.questions.status.update`, with `status`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('publishes a reviewed question and records who and when, once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC'));
    $question = Question::factory()->inReview()->create();

    $this->actingAs($this->admin)->put(route('admin.questions.status.update', $question), ['status' => 'published'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Question moved to Published.');

    expect($question->fresh())
        ->status->toBe(QuestionStatus::Published)
        ->reviewed_by_id->toBe($this->admin->id)
        ->published_at->toDateTimeString()->toBe('2026-10-14 09:00:00');

    $this->travel(2)->days();
    $question->fresh()->update(['status' => QuestionStatus::InReview]);
    $this->actingAs($this->admin)->put(route('admin.questions.status.update', $question), ['status' => 'published']);

    expect($question->fresh()->published_at->toDateTimeString())->toBe('2026-10-14 09:00:00');
});

it('refuses a jump the workflow does not allow', function () {
    $question = Question::factory()->create();

    $this->actingAs($this->admin)->put(route('admin.questions.status.update', $question), ['status' => 'published'])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', "A Draft question can't be moved to Published.");

    expect($question->fresh()->status)->toBe(QuestionStatus::Draft);
});

it('moves the valid questions in bulk and reports the skipped ones', function () {
    $drafts = Question::factory()->count(2)->create();
    $published = Question::factory()->published()->create();

    $this->actingAs($this->admin)->put(route('admin.questions.bulk-status'), [
        'ids' => [...$drafts->pluck('id'), $published->id],
        'status' => 'in_review',
    ])->assertInertiaFlash('toast.message', "2 questions moved to In review. 1 skipped because its status doesn't allow that.");

    expect($drafts->map->fresh()->pluck('status')->unique()->all())->toBe([QuestionStatus::InReview])
        ->and($published->fresh()->status)->toBe(QuestionStatus::Published);
});

it('validates bulk input', function () {
    $this->actingAs($this->admin)->put(route('admin.questions.bulk-status'), ['ids' => [], 'status' => 'nope'])
        ->assertSessionHasErrors(['ids', 'status']);
});

it('is only for admins', function () {
    $question = Question::factory()->inReview()->create();

    $this->actingAs(User::factory()->tutor()->create())
        ->put(route('admin.questions.status.update', $question), ['status' => 'published'])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run them to watch them fail**

Run: `php artisan test --compact tests/Feature/Admin/QuestionStatusTest.php`
Expected: FAIL (`Route [admin.questions.status.update] not defined`).

- [ ] **Step 3: Implement**

`ChangeQuestionStatus`:

```php
<?php

namespace App\Actions;

use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\User;

class ChangeQuestionStatus
{
    /**
     * Move a question along the review workflow. Returns false, changing nothing, if the move isn't allowed.
     */
    public function handle(Question $question, QuestionStatus $status, User $admin): bool
    {
        if (! $question->status->canMoveTo($status)) {
            return false;
        }

        $question->status = $status;

        if ($status === QuestionStatus::Published) {
            $question->reviewed_by_id = $admin->id;
            $question->published_at ??= now();
        }

        $question->save();

        return true;
    }
}
```

`QuestionStatusController`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeQuestionStatus;
use App\Enums\QuestionStatus;
use App\Http\Controllers\Controller;
use App\Models\Question;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class QuestionStatusController extends Controller
{
    public function update(Request $request, Question $question, ChangeQuestionStatus $changeStatus): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::enum(QuestionStatus::class)]]);
        $status = QuestionStatus::from($status['status']);
        $from = $question->status;

        Inertia::flash('toast', $changeStatus->handle($question, $status, $request->user())
            ? ['type' => 'success', 'message' => "Question moved to {$status->label()}."]
            : ['type' => 'error', 'message' => "A {$from->label()} question can't be moved to {$status->label()}."]);

        return back();
    }

    public function bulk(Request $request, ChangeQuestionStatus $changeStatus): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'status' => ['required', Rule::enum(QuestionStatus::class)],
        ]);
        $status = QuestionStatus::from($validated['status']);

        $moved = Question::whereKey($validated['ids'])->get()
            ->filter(fn (Question $question): bool => $changeStatus->handle($question, $status, $request->user()))
            ->count();
        $skipped = count($validated['ids']) - $moved;

        $message = $moved.' '.Str::plural('question', $moved)." moved to {$status->label()}.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped because ".($skipped === 1 ? 'its status doesn’t' : 'their status doesn’t')." allow that.";
        }

        Inertia::flash('toast', ['type' => $moved > 0 ? 'success' : 'error', 'message' => $message]);

        return back();
    }
}
```

Use a plain ASCII apostrophe (`doesn't`) in the messages so they match the test. The curly quote above is a typo to avoid.

Routes, above `Route::resource('questions', …)`:

```php
    Route::put('questions/bulk-status', [QuestionStatusController::class, 'bulk'])->name('questions.bulk-status');
    Route::put('questions/{question}/status', [QuestionStatusController::class, 'update'])->name('questions.status.update');
```

- **Frontend, edit page:** one button per `question.nextStatuses`, each inside its own `<Form {...statusUpdate.form(question.id)} options={{ preserveScroll: true }}>` with `<input type="hidden" name="status" value={next.value} />`.
    - "Published" uses the primary button with the label "Publish".
    - "In review" reads "Send for review".
    - "Draft" reads "Back to draft".
    - "Retired" is the outline button "Retire".
- **Frontend, index page:** a row checkbox, plus a select-all checkbox for the page, both held in `useState<number[]>`.
    - When any are selected, a sticky bar shows "{n} selected" with buttons "Send for review", "Publish" and "Retire".
    - Each button submits `<Form {...bulkStatus.form()}>` with hidden `ids[]` inputs and a `status`, then clears the selection `onSuccess`.

- [ ] **Step 4: Run the tests, checks and commit**

Run: `php artisan test --compact tests/Feature/Admin/QuestionStatusTest.php tests/Feature/Admin/QuestionBankTest.php && npm run check:fix && npm run types:check`
Expected: PASS, with no errors.

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/ChangeQuestionStatus.php app/Http/Controllers/Admin/QuestionStatusController.php routes/web.php resources/js/pages/admin/questions/edit.tsx resources/js/pages/admin/questions/index.tsx tests/Feature/Admin/QuestionStatusTest.php
git commit -m "feat: question review workflow with bulk status changes"
```

---

### Task 5: CSV upload with a downloadable template

**Files:**

- Create: `app/Actions/ImportQuestions.php`, `app/Http/Controllers/Admin/QuestionImportController.php`, `resources/js/pages/admin/questions/import.tsx`
- Modify: `routes/web.php`, `resources/js/pages/admin/questions/index.tsx` ("Upload CSV" link), `resources/js/types/global.d.ts` (`flashDataType.importErrors?: string[]`)
- Test: `tests/Feature/Admin/QuestionImportTest.php`

**Interfaces:**

- `ImportQuestions::HEADERS` (list<string>), `ImportQuestions::MAX_ROWS = 500`, `ImportQuestions::EXAMPLES` (list of rows for the template).
- `ImportQuestions::handle(string $path, User $author): array{imported: int, errors: list<string>}`
- **Routes** (above the resource):
    - `GET admin/questions/import` → `create`, named `admin.questions.import`.
    - `GET admin/questions/import/template` → `template`, named `admin.questions.import.template`.
    - `POST admin/questions/import` → `store`, named `admin.questions.import.store`.

**CSV columns:**

- **Required in the header:** `key_stage, strand, topic, difficulty, type, stem`.
- **Optional:** `year_group, option_a … option_f, correct, numeric_answer, numeric_tolerance, accepted_answers, explanation, marks, exam_tracks`.
- **Value formats:**
    - `correct` takes option letters separated by `;`, `,`, `|` or spaces (`B`, `A;C`).
    - `accepted_answers` and `exam_tracks` are separated by `|` or `;`.

- [ ] **Step 1: Write the failing tests**

```php
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
```

Note the curly quotes in the topic error (`There’s`, `“Dragons”`): that message uses typographic quotes deliberately, and it's the only one that does.

- [ ] **Step 2: Run them to watch them fail**

Run: `php artisan test --compact tests/Feature/Admin/QuestionImportTest.php`
Expected: FAIL (`Class "App\Actions\ImportQuestions" not found`).

- [ ] **Step 3: ImportQuestions**

```php
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
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            return 'The file has no questions in it.';
        }

        $header = array_map(fn (?string $cell): string => strtolower(trim((string) $cell)), $header);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
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
            $track ? $tracks[] = $track->value : $errors[] = "Exam track “{$name}” isn’t one of 11+, SATs or GCSE.";
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
```

Expected-message reconciliation:

- `Year 9 isn't` comes from `QuestionInput` and uses an ASCII apostrophe.
- The topic and key-stage messages use typographic quotes.
- The test strings above match these exactly.
- Replace the ternary-with-side-effects in the exam-track loop with an `if/else` so Pint and the reader are happy.

- [ ] **Step 4: Controller, routes, page**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ImportQuestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('admin/questions/import', [
            'columns' => ImportQuestions::HEADERS,
            'required' => ImportQuestions::REQUIRED,
            'maxRows' => ImportQuestions::MAX_ROWS,
        ]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ImportQuestions::HEADERS, escape: '');

            foreach (ImportQuestions::EXAMPLES as $example) {
                fputcsv($out, array_map(fn (string $column): string => $example[$column] ?? '', ImportQuestions::HEADERS), escape: '');
            }

            fclose($out);
        }, 'question-bank-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, ImportQuestions $importQuestions): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $result = $importQuestions->handle($request->file('file')->getRealPath(), $request->user());

        if ($result['errors'] !== []) {
            Inertia::flash('importErrors', $result['errors']);
            $rows = count(array_unique(array_map(fn (string $error): string => Str::before($error, ':'), $result['errors'])));
            $message = str_starts_with($result['errors'][0], 'Row ')
                ? "Nothing was imported. Fix the {$rows} ".Str::plural('row', $rows).' below and upload the file again.'
                : 'Nothing was imported.';

            return back()->withErrors(['file' => $message]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$result['imported']} ".Str::plural('question', $result['imported']).' imported as drafts. Review them, then publish.']);

        return to_route('admin.questions.index', ['status' => 'draft']);
    }
}
```

Routes, above the resource:

```php
    Route::get('questions/import/template', [QuestionImportController::class, 'template'])->name('questions.import.template');
    Route::get('questions/import', [QuestionImportController::class, 'create'])->name('questions.import');
    Route::post('questions/import', [QuestionImportController::class, 'store'])->name('questions.import.store');
```

**Page** (`admin/questions/import.tsx`, under AppLayout with the title "Upload questions" and subtitle "Question bank"):

1. **"How it works" card.** Steps: download the template, fill one row per question, upload. Every question is imported as a draft, a file with any problem imports nothing, and at most {maxRows} rows.
    - The template link is a plain `<a href={template.url()} download>` (a file download, not an Inertia visit).
2. **Columns table.** Each column has a short description, and the required ones are marked. For example:
    - `correct`: option letters, like `B` or `A;C`.
    - `accepted_answers` and `exam_tracks`: separated by `|`.
    - `stem`: maths between `$…$`.
3. **Upload form.** `<Form {...importStore.form()}>` with `<input type="file" name="file" accept=".csv,text/csv">`, a `FieldError` for `file`, and an Upload button.
    - When `usePage().flash.importErrors` is present, it renders under the error as an `<ol>` in a danger-tinted box (`role="alert"`), as a list of row problems.

Add `importErrors?: string[];` to `flashDataType` in `global.d.ts`. Add an "Upload CSV" outline link next to "Add question" on the index page.

- [ ] **Step 5: Run the tests, checks and commit**

Run: `php artisan test --compact tests/Feature/Admin/QuestionImportTest.php && npm run check:fix && npm run types:check && npm run build`
Expected: PASS, with no errors.

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/ImportQuestions.php app/Http/Controllers/Admin/QuestionImportController.php routes/web.php resources/js/pages/admin/questions/import.tsx resources/js/pages/admin/questions/index.tsx resources/js/types/global.d.ts tests/Feature/Admin/QuestionImportTest.php
git commit -m "feat: CSV question upload with a template, all-or-nothing validation"
```

---

### Task 6: Dashboard question bank count and demo questions

**Files:**

- Create: `database/seeders/DemoQuestionSeeder.php`
- Modify:
    - `app/Services/AdminDashboard.php` (`quizzes.questionBank` = published count)
    - `resources/js/pages/dashboard/admin.tsx` (Manage → link to the bank)
    - `database/seeders/DatabaseSeeder.php` (call `DemoQuestionSeeder` right after `CurriculumSeeder`)
- Test: `tests/Feature/AdminDashboardTest.php` (one new test), `tests/Feature/DemoQuestionSeederTest.php`

**Interfaces:**

- `DemoQuestionSeeder::QUESTIONS`: a list of `array{key_stage, strand, topic, year_group, difficulty, type, stem, options?, correct?, numeric_answer?, accepted_answers?, explanation, exam_tracks?}`. It is seeded through `QuestionInput::normalise` + `SaveQuestion` + `ChangeQuestionStatus` (Draft → InReview → Published), so demo data follows the real rules.

- [ ] **Step 1: Write the failing tests**

Append to `AdminDashboardTest.php`:

```php
it('counts only published questions in the question bank', function () {
    Question::factory()->published()->count(2)->create();
    Question::factory()->published()->create()->delete();
    Question::factory()->inReview()->create();
    Question::factory()->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('quizzes.questionBank', 2));
});
```

(Add `use App\Models\Question;` if it isn't imported.)

`tests/Feature/DemoQuestionSeederTest.php`:

```php
<?php

use App\Enums\KeyStage;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\DemoQuestionSeeder;

it('seeds published demo questions of every type and key stage, once', function () {
    User::factory()->admin()->create(['email' => 'admin@academy.test']);
    $this->seed(CurriculumSeeder::class);
    $this->seed(DemoQuestionSeeder::class);
    $this->seed(DemoQuestionSeeder::class);

    $questions = Question::with('topic')->get();

    expect($questions)->toHaveCount(count(DemoQuestionSeeder::QUESTIONS))
        ->and($questions->pluck('status')->unique()->all())->toBe([QuestionStatus::Published])
        ->and($questions->pluck('type')->unique()->sortBy->value->values()->all())->toEqualCanonicalizing(QuestionType::cases())
        ->and($questions->pluck('topic.key_stage')->unique()->values()->all())->toEqualCanonicalizing(KeyStage::cases());
});
```

- [ ] **Step 2: Run them to watch them fail**

Run: `php artisan test --compact tests/Feature/DemoQuestionSeederTest.php --filter=demo && php artisan test --compact tests/Feature/AdminDashboardTest.php --filter="only published"`
Expected: FAIL (the seeder class is missing, and `questionBank` is 0).

- [ ] **Step 3: Implement**

- **`AdminDashboard`:** set `'questionBank' => Question::published()->count()` and keep `available => false` (quiz activity comes in 5b).
- **`admin.tsx`:** replace the disabled "Manage · coming soon" span with `<Link href={questionsIndex()} className={buttonClasses('outline', 'sm')}>Manage</Link>` (import `index as questionsIndex` from `@/routes/admin/questions`). Keep the hint about quiz activity.
- **`DemoQuestionSeeder`:**
    - **Idempotency:** it returns early when `Question::where('stem', self::QUESTIONS[0]['stem'])->exists()` or when there's no admin.
    - **Content:** about 24 hand-written questions, 8 each for KS2, KS3 and GCSE. Together they cover all four strands and all four types, use `$…$` maths, and include SATs, 11+ and GCSE track tags.
    - **Each entry:** it resolves the topic by key stage, strand slug and topic name. Then it calls `QuestionInput::normalise()` and `SaveQuestion::handle($data, $admin)`, and moves the question through `ChangeQuestionStatus` to InReview, then Published.
    - **Topic names:** use `CurriculumSeeder::TOPICS` names exactly.
- **`DatabaseSeeder`:** add `$this->call(DemoQuestionSeeder::class);` after `CurriculumSeeder`. It no-ops without an admin, so a fresh seed (where the admin is created later in `run()`) also calls it again at the end, after `DemoDataSeeder`.

- [ ] **Step 4: Run the tests, seed locally, verify, commit**

Run: `php artisan test --compact tests/Feature/DemoQuestionSeederTest.php tests/Feature/AdminDashboardTest.php`
Expected: PASS.

Then, on the local DB (additive and idempotent, with no `migrate:fresh`): `php artisan db:seed --class=CurriculumSeeder --no-interaction && php artisan db:seed --class=DemoQuestionSeeder --no-interaction`. Screenshot `/dashboard` (the bank count is above 0, and Manage links to the bank) and `/admin/questions` at 360 and 1280.

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/DemoQuestionSeeder.php database/seeders/DatabaseSeeder.php app/Services/AdminDashboard.php resources/js/pages/dashboard/admin.tsx tests/Feature/AdminDashboardTest.php tests/Feature/DemoQuestionSeederTest.php
git commit -m "feat: dashboard question bank count and demo questions"
```

---

## After the last task

- Run the full suite: `php artisan test --compact` → all green.
- Do the final whole-branch review (a fresh reviewer on the most capable model) against this plan and its Review Focus.
- Update `docs/plans/implementation-plan.md`: mark D3 and D6 approved, and Phase 5 split into 5a (done) and 5b (recipes, picker, player, student home).
