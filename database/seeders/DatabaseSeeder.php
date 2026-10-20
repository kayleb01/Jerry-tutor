<?php

namespace Database\Seeders;

use App\Enums\Weekday;
use App\Enums\YearGroup;
use App\Models\ClassGroup;
use App\Models\Subject;
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
        $this->call([CurriculumSeeder::class, QuizCatalogueSeeder::class, PlanSeeder::class, DemoQuestionSeeder::class, DemoSubscriptionSeeder::class]);

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
            'year_group' => YearGroup::Year5,
            'is_active' => true,
        ]);
        $slot = $class->slots()->create([
            'subject_id' => Subject::firstOrCreate(['name' => 'Maths'])->id,
            'tutor_id' => $tutor->id,
            'location' => 'Room 1',
            'weekday' => Weekday::Saturday,
            'start_time' => '10:00',
            'duration_minutes' => 60,
        ]);
        $class->enrol($ava);
        app(LessonScheduler::class)->generateFor($slot);

        $this->call([DemoDataSeeder::class, DemoQuestionSeeder::class, DemoSubscriptionSeeder::class]);
    }
}
