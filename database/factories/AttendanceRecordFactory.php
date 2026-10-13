<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Enums\AttendeeRole;
use App\Models\AttendanceRecord;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'user_id' => User::factory()->student(),
            'attendee_role' => AttendeeRole::Student,
            'status' => AttendanceStatus::Present,
            'arrived_at' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])->starts_at,
            'minutes_late' => null,
            'recorded_by_id' => null,
            'notes' => null,
        ];
    }
}
