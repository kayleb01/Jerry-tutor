<?php

namespace App\Http\Controllers;

use App\Services\QuizCatalogue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuizController extends Controller
{
    /**
     * The student's quizzes home: their year, exam practice and the Challenge tier, with recent scores.
     */
    public function index(Request $request, QuizCatalogue $catalogue): Response
    {
        $student = $request->user()->load('studentProfile');

        return Inertia::render('quizzes/index', [
            'yearGroup' => $student->studentProfile?->year_group->label(),
            'sections' => $catalogue->sectionsFor($student),
            'recent' => $catalogue->recentFor($student),
        ]);
    }
}
