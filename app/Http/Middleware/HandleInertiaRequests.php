<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\LatenessReport;
use App\Models\Subscription;
use App\Services\ClassReportInbox;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'username', 'role']),
            ],
            'billing' => fn (): array => [
                // Students with no parent account pay for themselves, so they get a Billing menu item.
                'selfPay' => $request->user()?->role === Role::Student && ! $request->user()->guardians()->exists(),
            ],
            'badges' => fn (): array => $request->user()?->role === Role::Admin
                ? ['classReports' => $this->classReportsToReview(), 'lateness' => LatenessReport::whereNull('reviewed_at')->count(), 'subscriptions' => Subscription::where('status', SubscriptionStatus::PastDue)->count()]
                : [],
        ];
    }

    /**
     * Lessons with end-of-class reports no admin has reviewed yet, for the sidebar badge.
     */
    private function classReportsToReview(): int
    {
        $inbox = app(ClassReportInbox::class);

        return $inbox->query($inbox->filters([]), 'review')->count();
    }
}
