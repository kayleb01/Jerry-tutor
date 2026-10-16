<?php

namespace App\Enums;

enum RecommendedAction: string
{
    case FollowUpQuiz = 'follow_up_quiz';
    case MoveUpDifficulty = 'move_up_difficulty';
    case ExtraPractice = 'extra_practice';
    case SpeakToParent = 'speak_to_parent';
    case RecapNextClass = 'recap_next_class';

    public function label(): string
    {
        return match ($this) {
            self::FollowUpQuiz => 'Set a follow-up quiz',
            self::MoveUpDifficulty => 'Move up a difficulty level',
            self::ExtraPractice => 'Extra practice on this topic',
            self::SpeakToParent => 'Speak to a parent',
            self::RecapNextClass => 'Recap at the start of next class',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
