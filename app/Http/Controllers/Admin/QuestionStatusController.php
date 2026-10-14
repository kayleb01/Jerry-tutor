<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeQuestionStatus;
use App\Enums\QuestionStatus;
use App\Http\Controllers\Controller;
use App\Models\Question;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class QuestionStatusController extends Controller
{
    public function update(Request $request, Question $question, ChangeQuestionStatus $changeStatus): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::enum(QuestionStatus::class)]]);
        $status = QuestionStatus::from($status['status']);
        $from = $question->status;

        Inertia::flash('toast', $changeStatus->handle($question, $status, $request->user())
            ? ['type' => 'success', 'message' => "Question moved to {$status->label()}."]
            : ['type' => 'error', 'message' => "A {$from->label()} question can't be moved to {$status->label()}."]);

        return back();
    }

    public function bulk(Request $request, ChangeQuestionStatus $changeStatus): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'status' => ['required', Rule::enum(QuestionStatus::class)],
        ]);
        $status = QuestionStatus::from($validated['status']);

        $moved = Question::whereKey($validated['ids'])->get()
            ->filter(fn (Question $question): bool => $changeStatus->handle($question, $status, $request->user()))
            ->count();
        $skipped = count($validated['ids']) - $moved;

        $message = $moved.' '.Str::plural('question', $moved)." moved to {$status->label()}.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped because ".($skipped === 1 ? 'its status doesn\'t' : 'their statuses don\'t').' allow that.';
        }

        Inertia::flash('toast', ['type' => $moved > 0 ? 'success' : 'error', 'message' => $message]);

        return back();
    }
}
