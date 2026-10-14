<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ImportQuestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('admin/questions/import', [
            'columns' => ImportQuestions::HEADERS,
            'required' => ImportQuestions::REQUIRED,
            'maxRows' => ImportQuestions::MAX_ROWS,
        ]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ImportQuestions::HEADERS, escape: '');

            foreach (ImportQuestions::EXAMPLES as $example) {
                fputcsv($out, array_map(fn (string $column): string => $example[$column] ?? '', ImportQuestions::HEADERS), escape: '');
            }

            fclose($out);
        }, 'question-bank-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, ImportQuestions $importQuestions): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $result = $importQuestions->handle($request->file('file')->getRealPath(), $request->user());

        if ($result['errors'] !== []) {
            Inertia::flash('importErrors', $result['errors']);
            $rows = count(array_unique(array_map(fn (string $error): string => Str::before($error, ':'), $result['errors'])));
            $message = str_starts_with($result['errors'][0], 'Row ')
                ? "Nothing was imported. Fix the {$rows} ".Str::plural('row', $rows).' below and upload the file again.'
                : 'Nothing was imported.';

            return back()->withErrors(['file' => $message]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$result['imported']} ".Str::plural('question', $result['imported']).' imported as drafts. Review them, then publish.']);

        return to_route('admin.questions.index', ['status' => 'draft']);
    }
}
