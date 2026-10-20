<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClassSlotRequest;
use App\Models\ClassGroup;
use App\Models\ClassSlot;
use App\Services\LessonScheduler;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ClassSlotController extends Controller
{
    public function store(ClassSlotRequest $request, ClassGroup $classGroup, LessonScheduler $scheduler): RedirectResponse
    {
        $slot = $classGroup->slots()->create($request->validated());
        $scheduler->generateFor($slot);

        return $this->saved($classGroup, 'Slot added.');
    }

    public function update(ClassSlotRequest $request, ClassGroup $classGroup, ClassSlot $slot, LessonScheduler $scheduler): RedirectResponse
    {
        $slot->update($request->validated());
        $scheduler->rescheduleFuture($slot);

        return $this->saved($classGroup, 'Slot saved.');
    }

    public function destroy(ClassGroup $classGroup, ClassSlot $slot, LessonScheduler $scheduler): RedirectResponse
    {
        $scheduler->removeFuture($slot);
        $slot->delete();

        return $this->saved($classGroup, 'Slot removed.');
    }

    private function saved(ClassGroup $classGroup, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.classes.edit', $classGroup);
    }
}
