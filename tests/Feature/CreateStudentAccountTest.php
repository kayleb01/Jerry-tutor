<?php

use App\Actions\CreateStudentAccount;
use App\Enums\Role;
use App\Enums\YearGroup;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a student with a profile', function () {
    $student = app(CreateStudentAccount::class)->handle([
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'password' => 'a-strong-password',
        'year_group' => '5',
    ]);

    expect($student->role)->toBe(Role::Student)
        ->and($student->email)->toBeNull()
        ->and($student->studentProfile->year_group)->toBe(YearGroup::Year5)
        ->and(Hash::check('a-strong-password', $student->password))->toBeTrue();
});

it('marks a provided email as verified because a trusted adult created the account', function () {
    $student = app(CreateStudentAccount::class)->handle([
        'name' => 'Leo Okafor',
        'username' => 'leo.okafor',
        'email' => 'leo@example.com',
        'password' => 'a-strong-password',
        'year_group' => 8,
    ]);

    expect($student->hasVerifiedEmail())->toBeTrue()
        ->and($student->email_verified_at)->not->toBeNull();
});

it('links the new student to the guardian', function () {
    $guardian = User::factory()->guardian()->create();

    $student = app(CreateStudentAccount::class)->handle([
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'password' => 'a-strong-password',
        'year_group' => 5,
    ], $guardian, 'Mother');

    expect($guardian->children()->first()->is($student))->toBeTrue();
});

it('leaves nothing behind if linking fails', function () {
    $tutor = User::factory()->tutor()->create();

    expect(fn () => app(CreateStudentAccount::class)->handle([
        'name' => 'Ava Okafor',
        'username' => 'ava.okafor',
        'password' => 'a-strong-password',
        'year_group' => 5,
    ], $tutor))->toThrow(InvalidArgumentException::class);

    expect(User::where('username', 'ava.okafor')->exists())->toBeFalse()
        ->and(StudentProfile::count())->toBe(0);
});
