<?php

namespace App\Models;

use Database\Factories\StrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the four maths strands: Number, Algebra, Statistics, Geometry.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 */
#[Fillable(['name', 'slug', 'sort_order'])]
class Strand extends Model
{
    /** @use HasFactory<StrandFactory> */
    use HasFactory;

    /**
     * @return HasMany<Topic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderBy('sort_order');
    }
}
