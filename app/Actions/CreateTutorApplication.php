<?php

namespace App\Actions;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateTutorApplication
{
    /**
     * Create an unverified, pending tutor with the class and subject pairs they asked to teach.
     *
     * @param  array{name: string, email: string, phone: string, password: string}  $account
     * @param  array<int, array{class_group_id: int, subject_id: int}>  $classes
     */
    public function handle(array $account, array $classes): User
    {
        return DB::transaction(function () use ($account, $classes): User {
            $user = new User([
                'name' => $account['name'],
                'email' => $account['email'],
                'phone' => $account['phone'],
                'password' => Hash::make($account['password']),
            ]);
            $user->forceFill(['role' => Role::Tutor, 'approval_status' => ApprovalStatus::Pending])->save();

            $user->requestedClasses()->createMany($classes);

            return $user;
        });
    }
}
