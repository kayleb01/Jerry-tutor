<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Plan;
use App\Models\User;
use App\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * The parent's overview: for each of their children, quiz access, attendance, the next class and quiz progress.
 */
class GuardianDashboard
{
    public function __construct(private AttendanceStats $attendanceStats, private QuizProgress $quizProgress, private QuizAccessState $quizAccessState, private PaymentGatewayManager $gateways) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function childrenOf(User $guardian, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return $guardian->children()
            ->with('studentProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $child): array => [
                'id' => $child->id,
                'name' => $child->name,
                'yearGroup' => $child->studentProfile?->year_group->label(),
                'access' => Arr::only($this->quizAccessState->for($child, $guardian, $now), ['state', 'note', 'paymentFailed']),
                'attendance' => $this->attendanceStats->forUser($child, $now),
                'nextLesson' => $this->nextLesson($child, $now),
                'quizzes' => $this->quizProgress->forStudent($child, $now),
            ])
            ->all();
    }

    /**
     * Whether a parent could subscribe today: some active plan is linked to the default gateway.
     */
    public function paymentsAvailable(): bool
    {
        $gateway = $this->gateways->defaultGateway();

        return Plan::query()->active()->get()->contains(fn (Plan $plan): bool => $plan->isLinkedTo($gateway));
    }

    /**
     * @return array{className: string, when: string}|null
     */
    private function nextLesson(User $child, CarbonImmutable $now): ?array
    {
        $lesson = Lesson::query()
            ->whereIn('class_group_id', $child->enrolledClassGroups()->wherePivotNull('left_at')->select('class_groups.id'))
            ->where('starts_at', '>', $now->utc())
            ->where('status', '!=', LessonStatus::Cancelled)
            ->with(['classGroup', 'slot.subject'])
            ->orderBy('starts_at')
            ->first();

        return $lesson ? [
            'className' => $lesson->title(),
            'when' => $lesson->localStart()->format('D j M, H:i'),
        ] : null;
    }
}
