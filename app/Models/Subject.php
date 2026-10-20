<?php

namespace App\Models;

use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A subject taught in class slots, such as Maths.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['name', 'is_active', 'sort_order'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Active subjects in display order.
     *
     * @param  Builder<Subject>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Active subjects as select options.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return static::query()->active()->get()
            ->map(fn (Subject $subject): array => ['value' => $subject->id, 'label' => $subject->name])
            ->all();
    }
}
