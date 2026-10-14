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

it('leaves a real question bank alone', function () {
    User::factory()->admin()->create(['email' => 'owner@school.example']);
    $this->seed(CurriculumSeeder::class);
    $this->seed(DemoQuestionSeeder::class);

    expect(Question::count())->toBe(0);
});
