<?php

namespace Database\Factories;

use App\Enums\Difficulty;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
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
        return $this->state([
            'type' => QuestionType::Numeric,
            'numeric_answer' => $answer,
            'numeric_tolerance' => $tolerance,
        ]);
    }

    /**
     * @param  array<int, string>  $answers
     */
    public function shortText(array $answers = ['twelve']): static
    {
        return $this->state([
            'type' => QuestionType::ShortText,
            'accepted_answers' => $answers,
        ]);
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
        return $this->state([
            'status' => QuestionStatus::Retired,
            'published_at' => now()->subMonth(),
        ]);
    }
}
