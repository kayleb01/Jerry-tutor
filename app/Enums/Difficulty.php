<?php

namespace App\Enums;

enum Difficulty: string
{
    case Easy = 'easy';
    case Intermediate = 'intermediate';
    case Hard = 'hard';
    case Challenging = 'challenging';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
