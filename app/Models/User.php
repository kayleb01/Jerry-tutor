<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string|null $email
 * @property string|null $phone
 * @property Role $role
 * @property ApprovalStatus|null $approval_status
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'approval_status' => ApprovalStatus::class,
        ];
    }

    /**
     * Emails are stored lowercase so login and uniqueness checks are case-insensitive.
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : Str::lower(trim($value)));
    }

    /**
     * Usernames are stored lowercase so login and uniqueness checks are case-insensitive.
     */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : Str::lower(trim($value)));
    }

    /**
     * Students created without an email have nothing to verify.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email === null || $this->email_verified_at !== null;
    }

    /**
     * Never try to email an account that has no address.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->email !== null) {
            parent::sendEmailVerificationNotification();
        }
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isApprovedTutor(): bool
    {
        return $this->hasRole(Role::Tutor) && $this->approval_status === ApprovalStatus::Approved;
    }

    public function isPendingTutor(): bool
    {
        return $this->hasRole(Role::Tutor) && $this->approval_status === ApprovalStatus::Pending;
    }

    public function isRejectedTutor(): bool
    {
        return $this->hasRole(Role::Tutor) && $this->approval_status === ApprovalStatus::Rejected;
    }

    /**
     * @return HasOne<StudentProfile, $this>
     */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student', 'guardian_id', 'student_id')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student', 'student_id', 'guardian_id')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    /**
     * Subscriptions this user pays for.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'payer_id');
    }

    /**
     * Subscriptions that cover this student (whoever pays).
     *
     * @return BelongsToMany<Subscription, $this>
     */
    public function coveringSubscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class, 'subscription_student', 'student_id', 'subscription_id')->withTimestamps();
    }

    /**
     * Staff always have quiz access; a student needs a subscription that is paid up (or within grace).
     */
    public function hasQuizAccess(): bool
    {
        if ($this->hasRole(Role::Admin, Role::Tutor)) {
            return true;
        }

        return $this->hasRole(Role::Student) && $this->coveringSubscriptions()->grantingAccess()->exists();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function linkChild(User $student, ?string $relationship = null): void
    {
        if ($this->is($student) || ! $this->hasRole(Role::Guardian) || ! $student->hasRole(Role::Student)) {
            throw new InvalidArgumentException('Only a guardian can be linked to a different student.');
        }

        $this->children()->syncWithoutDetaching([
            $student->id => ['relationship' => $relationship],
        ]);
    }

    /**
     * The timetable slots this tutor teaches; removed slots are excluded.
     *
     * @return HasMany<ClassSlot, $this>
     */
    public function classSlotsTaught(): HasMany
    {
        return $this->hasMany(ClassSlot::class, 'tutor_id');
    }

    /**
     * @return BelongsToMany<ClassGroup, $this>
     */
    public function enrolledClassGroups(): BelongsToMany
    {
        return $this->belongsToMany(ClassGroup::class, 'class_group_student', 'student_id', 'class_group_id')
            ->withPivot(['enrolled_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessonsTaught(): HasMany
    {
        return $this->hasMany(Lesson::class, 'tutor_id');
    }
}
