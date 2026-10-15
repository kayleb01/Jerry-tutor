<?php

namespace Database\Seeders;

use App\Enums\Difficulty;
use App\Enums\ExamTrack;
use App\Enums\KeyStage;
use App\Enums\YearGroup;
use App\Models\Quiz;
use App\Models\Strand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The standard quiz recipes: each year's mixed and strand quizzes at its default difficulty, the KS3
 * Challenge tier, GCSE, and 11+ and SATs practice. Safe to run repeatedly (matched on slug).
 */
class QuizCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $strands = Strand::query()->orderBy('sort_order')->get();
        $order = 0;

        $make = function (string $title, array $recipe) use (&$order): void {
            Quiz::updateOrCreate(['slug' => Str::slug($title)], [
                'title' => $title,
                'key_stage' => null,
                'year_group' => null,
                'exam_track' => null,
                'difficulty' => null,
                'strand_id' => null,
                'topic_id' => null,
                'question_count' => 10,
                'is_published' => true,
                'sort_order' => $order++,
                ...$recipe,
            ]);
        };

        $withStrands = function (string $prefix, array $recipe) use ($make, $strands): void {
            $make("{$prefix} mixed", $recipe);

            foreach ($strands as $strand) {
                $make("{$prefix} {$strand->name}", [...$recipe, 'strand_id' => $strand->id]);
            }
        };

        foreach (YearGroup::cases() as $year) {
            if ($year->defaultDifficulty() === null) {
                continue;
            }

            $withStrands($year->label(), [
                'key_stage' => $year->keyStage(),
                'year_group' => $year,
                'difficulty' => $year->defaultDifficulty(),
            ]);
        }

        $withStrands('KS3 Challenge', ['key_stage' => KeyStage::Ks3, 'difficulty' => Difficulty::Challenging]);
        $withStrands('GCSE', ['key_stage' => KeyStage::Ks4, 'exam_track' => ExamTrack::Gcse]);

        $make('11+ practice', ['key_stage' => KeyStage::Ks2, 'exam_track' => ExamTrack::ElevenPlus]);
        $make('SATs practice', ['key_stage' => KeyStage::Ks2, 'exam_track' => ExamTrack::Sats]);
    }
}
