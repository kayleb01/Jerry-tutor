<?php

namespace App\Actions;

use App\Enums\ApprovalStatus;
use App\Mail\TutorApprovedMail;
use App\Models\ClassSlot;
use App\Models\TutorClassRequest;
use App\Models\User;
use App\Services\LessonScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ApproveTutorApplication
{
    public function __construct(private LessonScheduler $scheduler) {}

    /**
     * Apply the admin's validated decisions, approve the tutor and email them once it has all saved.
     * Handing a slot over rebuilds only its future unattended lessons, so taught lessons keep their tutor.
     * A failed email is reported but never undoes the approval; the return value says whether it went.
     *
     * @param  array<int, array{request_id: int, slot_id: int|null, action: string, weekday?: int|null, start_time?: string|null, duration_minutes?: int|null, location?: string|null}>  $decisions
     */
    public function handle(User $tutor, array $decisions): bool
    {
        $emailSent = true;

        DB::transaction(function () use ($tutor, $decisions, &$emailSent): void {
            $requests = $tutor->requestedClasses()->get()->keyBy('id');

            foreach ($decisions as $decision) {
                match ($decision['action']) {
                    'assign', 'handover' => $this->teach($tutor, (int) $decision['slot_id']),
                    'create' => $this->createSlot($tutor, $requests->get((int) $decision['request_id']), $decision),
                    default => null,
                };
            }

            $tutor->forceFill(['approval_status' => ApprovalStatus::Approved])->save();

            DB::afterCommit(function () use ($tutor, &$emailSent): void {
                try {
                    Mail::to($tutor->email)->send(new TutorApprovedMail($tutor));
                } catch (Throwable $exception) {
                    report($exception);
                    $emailSent = false;
                }
            });
        });

        return $emailSent;
    }

    private function teach(User $tutor, int $slotId): void
    {
        $slot = ClassSlot::query()->lockForUpdate()->findOrFail($slotId);
        $slot->update(['tutor_id' => $tutor->id]);

        $this->scheduler->rescheduleFuture($slot);
    }

    /**
     * @param  array{weekday?: int|null, start_time?: string|null, duration_minutes?: int|null, location?: string|null}  $decision
     */
    private function createSlot(User $tutor, TutorClassRequest $request, array $decision): void
    {
        $slot = ClassSlot::query()->create([
            'class_group_id' => $request->class_group_id,
            'subject_id' => $request->subject_id,
            'tutor_id' => $tutor->id,
            'weekday' => (int) $decision['weekday'],
            'start_time' => $decision['start_time'],
            'duration_minutes' => (int) $decision['duration_minutes'],
            'location' => $decision['location'] ?? null,
        ]);

        $this->scheduler->generateFor($slot);
    }
}
