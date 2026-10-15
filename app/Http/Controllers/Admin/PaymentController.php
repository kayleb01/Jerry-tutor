<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\SubscriptionsOverview;
use App\Support\AcademyDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    /**
     * Every payment, newest first, with the same filters as the payments report.
     */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('admin/payments/index', [
            'payments' => $this->query($filters)
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Payment $payment): array => SubscriptionsOverview::paymentRow($payment)),
            'filters' => [
                ...$filters,
                'from' => $filters['from']?->toDateString(),
                'to' => $filters['to']?->toDateString(),
            ],
            'statuses' => array_map(fn (PaymentStatus $status): array => ['value' => $status->value, 'label' => $status->label()], PaymentStatus::cases()),
            'gateways' => array_map(fn (PaymentGatewayName $gateway): array => ['value' => $gateway->value, 'label' => $gateway->label()], PaymentGatewayName::cases()),
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'name'])->map(fn (Plan $plan): array => ['value' => $plan->id, 'label' => $plan->name]),
        ]);
    }

    /**
     * The payments report: the filtered payments as CSV, amounts in naira.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $timezone = config('academy.timezone');

        return response()->streamDownload(function () use ($filters, $timezone): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Payer', 'Email', 'Plan', 'Amount (NGN)', 'Method', 'Gateway', 'Status', 'Failure reason', 'Reference'], escape: '');

            $this->query($filters)->lazy(500)->each(function (Payment $payment) use ($out, $timezone): void {
                fputcsv($out, array_map($this->safeCell(...), [
                    ($payment->paid_at ?? $payment->created_at)->setTimezone($timezone)->toDateString(),
                    $payment->payer->name,
                    (string) ($payment->subscription?->billingEmail() ?? $payment->payer->email),
                    $payment->subscription ? SubscriptionsOverview::shortName($payment->subscription->plan->name) : '',
                    number_format($payment->amount / 100, 2, '.', ''),
                    $payment->card_last4 ? trim(($payment->card_brand ?? 'Card').' '.$payment->card_last4) : '',
                    $payment->gateway->label(),
                    $payment->status->label(),
                    (string) $payment->failure_reason,
                    $payment->reference,
                ]), escape: '');
            });

            fclose($out);
        }, 'payments-'.CarbonImmutable::now($timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{status: string|null, gateway: string|null, plan: int|null, search: string, from: CarbonImmutable|null, to: CarbonImmutable|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => PaymentStatus::tryFrom((string) $request->query('status'))?->value,
            'gateway' => PaymentGatewayName::tryFrom((string) $request->query('gateway'))?->value,
            'plan' => filter_var($request->query('plan'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
            'search' => Str::lower(trim((string) $request->query('search'))),
            'from' => AcademyDate::fromQuery($request->query('from')),
            'to' => AcademyDate::fromQuery($request->query('to')),
        ];
    }

    /**
     * @param  array{status: string|null, gateway: string|null, plan: int|null, search: string, from: CarbonImmutable|null, to: CarbonImmutable|null}  $filters
     * @return Builder<Payment>
     */
    private function query(array $filters): Builder
    {
        $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';

        return Payment::query()
            ->with(['payer', 'subscription.plan'])
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['gateway'], fn (Builder $query, string $gateway) => $query->where('gateway', $gateway))
            ->when($filters['plan'], fn (Builder $query, int $plan) => $query->whereHas('subscription', fn (Builder $subscription) => $subscription->where('plan_id', $plan)))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->whereHas('payer', fn (Builder $payer) => $payer->withTrashed()->where(fn (Builder $payer) => $payer
                ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$search])
                ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$search]))))
            ->when($filters['from'], fn (Builder $query, CarbonImmutable $from) => $query->where('created_at', '>=', $from->startOfDay()->utc()))
            ->when($filters['to'], fn (Builder $query, CarbonImmutable $to) => $query->where('created_at', '<=', $to->endOfDay()->utc()))
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * Stop spreadsheet apps from treating a cell as a formula.
     */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
