<?php

namespace App\Enums;

enum QuestionStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In review',
            self::Published => 'Published',
            self::Retired => 'Retired',
        };
    }

    /**
     * The statuses a question may move to from this one. Publishing always goes through review.
     *
     * @return array<int, self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Draft => [self::InReview],
            self::InReview => [self::Draft, self::Published],
            self::Published => [self::Retired],
            self::Retired => [self::Draft],
        };
    }

    public function canMoveTo(self $status): bool
    {
        return in_array($status, $this->nextStatuses(), true);
    }

    /**
     * Published and retired questions are kept so served quizzes keep their history.
     */
    public function isDeletable(): bool
    {
        return $this === self::Draft || $this === self::InReview;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
