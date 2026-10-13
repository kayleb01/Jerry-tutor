<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateStudentAccount
{
    /**
     * Create a student and their profile, optionally linked to a guardian, all or nothing.
     *
     * @param  array{name: string, username: string, email?: string|null, password: string, year_group: int|string, date_of_birth?: string|null}  $data
     */
    public function handle(array $data, ?User $guardian = null, ?string $relationship = null): User
    {
        return DB::transaction(function () use ($data, $guardian, $relationship): User {
            $email = $data['email'] ?? null;

            $student = new User([
                'name' => $data['name'],
                'email' => $email,
                'password' => Hash::make($data['password']),
            ]);
            $student->forceFill([
                'role' => Role::Student,
                'username' => $data['username'],
                'email_verified_at' => $email === null ? null : now(),
            ])->save();

            $student->studentProfile()->create([
                'year_group' => (int) $data['year_group'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
            ]);

            $guardian?->linkChild($student, $relationship);

            return $student;
        });
    }
}
