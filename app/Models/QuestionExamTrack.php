<?php

namespace App\Models;

use App\Enums\ExamTrack;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tags a question with an exam track (11+, SATs, GCSE).
 *
 * @property int $id
 * @property int $question_id
 * @property ExamTrack $exam_track
 */
#[Fillable(['question_id', 'exam_track'])]
class QuestionExamTrack extends Model
{
    protected $table = 'exam_track_question';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_track' => ExamTrack::class,
        ];
    }
}
