<?php

namespace Database\Factories;

use App\Enums\Difficulty;
use App\Enums\KeyStage;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = 'Quiz '.fake()->unique()->numberBetween(1, 99999);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'key_stage' => KeyStage::Ks2,
            'year_group' => null,
            'exam_track' => null,
            'difficulty' => Difficulty::Easy,
            'strand_id' => null,
            'topic_id' => null,
            'question_count' => 10,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }
}
