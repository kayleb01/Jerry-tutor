<?php

namespace App\Http\Controllers;

use App\Models\GuardianNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GuardianNoticeController extends Controller
{
    /**
     * A parent or guardian of the student confirms they've read the academy's note. One acknowledgement covers the family.
     */
    public function acknowledge(Request $request, GuardianNotice $guardianNotice): RedirectResponse
    {
        abort_unless($request->user()->children()->whereKey($guardianNotice->student_id)->exists(), 403);

        if ($guardianNotice->acknowledged_at === null) {
            $guardianNotice->update(['acknowledged_at' => now(), 'acknowledged_by_id' => $request->user()->id]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Thanks. The academy can see you've read this."]);

        return back();
    }
}
