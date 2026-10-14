<?php

namespace App\Actions;

use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\User;

class ChangeQuestionStatus
{
    /**
     * Move a question along the review workflow. Returns false, changing nothing, if the move isn't allowed.
     */
    public function handle(Question $question, QuestionStatus $status, User $admin): bool
    {
        if (! $question->status->canMoveTo($status)) {
            return false;
        }

        $question->status = $status;

        if ($status === QuestionStatus::Published) {
            $question->reviewed_by_id = $admin->id;
            $question->published_at ??= now();
        }

        $question->save();

        return true;
    }
}
