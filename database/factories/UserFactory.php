<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Guardian,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Admin]);
    }

    public function tutor(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Tutor, 'approval_status' => ApprovalStatus::Approved]);
    }

    public function pendingTutor(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Tutor, 'approval_status' => ApprovalStatus::Pending]);
    }

    public function rejectedTutor(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Tutor, 'approval_status' => ApprovalStatus::Rejected]);
    }

    public function guardian(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Guardian]);
    }

    public function student(YearGroup $yearGroup = YearGroup::Year5): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Student,
            'username' => Str::lower(fake()->unique()->userName()),
        ])
            ->afterCreating(fn (User $user) => StudentProfile::factory()
                ->for($user)
                ->create(['year_group' => $yearGroup]));
    }

    /**
     * A student whose free trial runs until the given time (default: a month from now).
     */
    public function onTrial(?CarbonImmutable $endsAt = null): static
    {
        return $this->state(fn (array $attributes) => ['trial_ends_at' => $endsAt ?? CarbonImmutable::now()->addDays(30)]);
    }
}
