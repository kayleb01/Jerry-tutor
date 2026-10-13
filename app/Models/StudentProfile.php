<?php

namespace App\Models;

use App\Enums\KeyStage;
use App\Enums\YearGroup;
use Database\Factories\StudentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property YearGroup $year_group
 * @property Carbon|null $date_of_birth
 * @property string|null $school_name
 * @property bool $challenging_tier_unlocked
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'year_group', 'date_of_birth', 'school_name', 'challenging_tier_unlocked'])]
class StudentProfile extends Model
{
    /** @use HasFactory<StudentProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_group' => YearGroup::class,
            'date_of_birth' => 'date',
            'challenging_tier_unlocked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Challenging tier is a KS3-only extra level.
     */
    public function hasChallengingTier(): bool
    {
        return $this->challenging_tier_unlocked
            && $this->year_group->keyStage() === KeyStage::Ks3;
    }
}
