<?php

namespace App\Http\Controllers;

use App\Models\RaisedIssue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RaisedIssueController extends Controller
{
    /**
     * The tutor confirms they've read a note the academy raised about one of their classes.
     */
    public function acknowledge(Request $request, RaisedIssue $raisedIssue): RedirectResponse
    {
        abort_unless($raisedIssue->tutor_id === $request->user()->id, 403);

        $raisedIssue->acknowledged_at ??= now();
        $raisedIssue->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Thanks. The academy can see you've read this."]);

        return back();
    }
}
