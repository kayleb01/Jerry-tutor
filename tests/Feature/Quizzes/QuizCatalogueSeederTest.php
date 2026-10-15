<?php

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Models\Quiz;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\QuizCatalogueSeeder;

beforeEach(fn () => $this->seed(CurriculumSeeder::class));

it('seeds the quiz recipes once', function () {
    $this->seed(QuizCatalogueSeeder::class);
    $this->seed(QuizCatalogueSeeder::class);

    // Years 3-9: mixed + 4 strands each; KS3 challenge: 5; GCSE: 5; 11+ and SATs: 2.
    expect(Quiz::count())->toBe(7 * 5 + 5 + 5 + 2)
        ->and(Quiz::where('is_published', false)->count())->toBe(0);

    $year5Number = Quiz::firstWhere('slug', 'year-5-number');
    expect($year5Number)
        ->title->toBe('Year 5 Number')
        ->key_stage->toBe(KeyStage::Ks2)
        ->year_group->value->toBe(5)
        ->difficulty->toBe(Difficulty::Intermediate)
        ->question_count->toBe(10)
        ->and($year5Number->strand->slug)->toBe('number');

    expect(Quiz::firstWhere('slug', 'year-7-mixed'))->strand_id->toBeNull()->difficulty->toBe(Difficulty::Easy)
        ->and(Quiz::firstWhere('slug', 'ks3-challenge-algebra'))->difficulty->toBe(Difficulty::Challenging)->year_group->toBeNull()
        ->and(Quiz::firstWhere('slug', 'gcse-geometry'))->exam_track->toBe(ExamTrack::Gcse)->key_stage->toBe(KeyStage::Ks4)->difficulty->toBeNull()
        ->and(Quiz::firstWhere('slug', 'sats-practice'))->exam_track->toBe(ExamTrack::Sats)->key_stage->toBe(KeyStage::Ks2);
});
