<?php

namespace App\Models;

use App\Enums\KeyStage;
use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A National Curriculum topic within a strand and key stage, e.g. KS2 Number: Fractions.
 *
 * @property int $id
 * @property int $strand_id
 * @property KeyStage $key_stage
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 */
#[Fillable(['strand_id', 'key_stage', 'name', 'slug', 'description', 'sort_order'])]
class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_stage' => KeyStage::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Strand, $this>
     */
    public function strand(): BelongsTo
    {
        return $this->belongsTo(Strand::class);
    }

    /**
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
