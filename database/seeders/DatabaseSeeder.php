<?php

namespace Database\Seeders;

use App\Enums\Weekday;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\User;
use App\Services\LessonScheduler;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed demo accounts for each role. All use the password "password";
     * students log in with their username (ava.year5 has no email).
     * Also seeds one Saturday class with Ava enrolled and lessons scheduled.
     */
    public function run(): void
    {
        if (User::where('email', 'admin@academy.test')->exists()) {
            return;
        }

        User::factory()->admin()->create(['name' => 'Academy Admin', 'email' => 'admin@academy.test']);
        $tutor = User::factory()->tutor()->create(['name' => 'Demo Tutor', 'email' => 'tutor@academy.test']);

        $parent = User::factory()->guardian()->create(['name' => 'Demo Parent', 'email' => 'parent@academy.test']);

        $ava = User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava (Year 5)', 'username' => 'ava.year5', 'email' => null]);
        $parent->linkChild($ava, 'Parent');
        $parent->linkChild(
            User::factory()->student(YearGroup::Year8)->create(['name' => 'Leo (Year 8)', 'username' => 'leo.year8', 'email' => 'ks3.student@academy.test']),
            'Parent',
        );

        $class = ClassGroup::create([
            'name' => 'Year 5 Maths – Saturday',
            'tutor_id' => $tutor->id,
            'year_group' => YearGroup::Year5,
            'location' => 'Room 1',
            'weekday' => Weekday::Saturday,
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'is_active' => true,
        ]);
        $class->enrol($ava);
        app(LessonScheduler::class)->generateFor($class);

        $this->call(DemoDataSeeder::class);
    }
}
