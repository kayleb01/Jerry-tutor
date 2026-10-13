<?php

namespace Database\Seeders;

use App\Enums\YearGroup;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed demo accounts for each role. All use the password "password";
     * students log in with their username (ava.year5 has no email).
     */
    public function run(): void
    {
        if (User::where('email', 'admin@academy.test')->exists()) {
            return;
        }

        User::factory()->admin()->create(['name' => 'Academy Admin', 'email' => 'admin@academy.test']);
        User::factory()->tutor()->create(['name' => 'Demo Tutor', 'email' => 'tutor@academy.test']);

        $parent = User::factory()->guardian()->create(['name' => 'Demo Parent', 'email' => 'parent@academy.test']);

        $parent->linkChild(
            User::factory()->student(YearGroup::Year5)->create(['name' => 'Ava (Year 5)', 'username' => 'ava.year5', 'email' => null]),
            'Parent',
        );
        $parent->linkChild(
            User::factory()->student(YearGroup::Year8)->create(['name' => 'Leo (Year 8)', 'username' => 'leo.year8', 'email' => 'ks3.student@academy.test']),
            'Parent',
        );
    }
}
