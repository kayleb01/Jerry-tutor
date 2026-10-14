<?php

namespace Database\Factories;

use App\Enums\KeyStage;
use App\Models\Strand;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
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
}
