<?php

namespace App\Actions;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The only writer of a question, its options and its exam tracks. New questions start as drafts;
 * saving never changes an existing question's status.
 */
class SaveQuestion
{
    /**
     * @param  array<string, mixed>  $data  validated QuestionInput data
     */
    public function handle(array $data, User $author, ?Question $question = null, ?UploadedFile $image = null, bool $removeImage = false): Question
    {
        $question ??= new Question(['status' => QuestionStatus::Draft, 'author_id' => $author->id]);
        $previousImage = $question->image_path;
        $type = QuestionType::from($data['type']);

        DB::transaction(function () use ($question, $data, $type, $image, $removeImage): void {
            $question->fill([
                'topic_id' => $data['topic_id'],
                'difficulty' => $data['difficulty'],
                'year_group' => $data['year_group'],
                'type' => $type,
                'stem' => $data['stem'],
                'explanation' => $data['explanation'],
                'marks' => $data['marks'],
                'numeric_answer' => $type === QuestionType::Numeric ? $data['numeric_answer'] : null,
                'numeric_tolerance' => $type === QuestionType::Numeric ? $data['numeric_tolerance'] : 0,
                'accepted_answers' => $type === QuestionType::ShortText ? $data['accepted_answers'] : null,
            ]);

            if ($image) {
                $question->image_path = $image->store('questions', 'public');
            } elseif ($removeImage) {
                $question->image_path = null;
            }

            $question->save();

            $options = $type->hasOptions() ? $data['options'] : [];

            foreach ($options as $index => $body) {
                $question->options()->updateOrCreate(
                    ['sort_order' => $index],
                    ['body' => $body, 'is_correct' => in_array($index, $data['correct'], true)],
                );
            }

            $question->options()->where('sort_order', '>=', count($options))->delete();

            $question->examTrackRows()->delete();

            foreach (array_unique($data['exam_tracks']) as $track) {
                $question->examTrackRows()->create(['exam_track' => $track]);
            }
        });

        if ($previousImage && $previousImage !== $question->image_path) {
            Storage::disk('public')->delete($previousImage);
        }

        return $question->load(['options', 'examTrackRows']);
    }
}
