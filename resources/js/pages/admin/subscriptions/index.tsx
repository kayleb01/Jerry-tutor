import { Form, Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import PaymentsTable from '@/components/payments-table';
import type { PaymentRow } from '@/components/payments-table';
import PersonAvatar from '@/components/person-avatar';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as billingSettings } from '@/routes/admin/billing';
import {
    exportMethod as paymentsReport,
    index as allPayments,
} from '@/routes/admin/payments';
import { pause, remind, retry } from '@/routes/admin/subscriptions';

type Stats = {
    active: number;
    activeByPlan: string;
    mrr: string;
    failed: number;
    graceDays: number;
    cancelledThisMonth: number;
    topReason: string | null;
};

type PlanRow = {
    id: number;
    name: string;
    detail: string;
    subscribers: number;
    isFamily: boolean;
};

type AttentionRow = {
    id: number;
    payer: string;
    plan: string;
    problem: string;
    attempts: number;
    processing: boolean;
    remindedAt: string | null;
    canRetry: boolean;
    canRemind: boolean;
};

type SubscriptionsProps = {
    stats: Stats;
    plans: PlanRow[];
    attention: AttentionRow[];
    payments: PaymentRow[];
};

/** Retries stop after the third failed attempt; from then on the admin decides whether to pause. */
const MAX_ATTEMPTS = 3;

const smallCaps =
    'text-[0.6875rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

export default function Subscriptions({
    stats,
    plans,
    attention,
    payments,
}: SubscriptionsProps) {
    return (
        <AppLayout
            title="Subscriptions"
            subtitle="Payments"
            actions={
                <div className="flex flex-wrap gap-2">
                    <Link
                        href={billingSettings()}
                        className={buttonClasses('outline')}
                    >
                        <Icon name="filter" className="size-4" />
                        Edit plans &amp; prices
                    </Link>
                    <a
                        href={paymentsReport.url()}
                        className={buttonClasses('primary')}
                    >
                        <Icon name="download" className="size-4" />
                        Payments report
                    </a>
                </div>
            }
        >
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Monthly access to the quiz platform and exam preparation.
            </p>

            <section
                aria-label="Subscription figures"
                className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4"
            >
                <Kpi
                    label="Active subscriptions"
                    value={stats.active.toLocaleString('en-GB')}
                    note={stats.activeByPlan || 'None yet'}
                />
                <Kpi
                    label="Monthly recurring revenue"
                    value={stats.mrr}
                    note="Before payment provider fees"
                />
                <Kpi
                    label="Failed payments"
                    value={String(stats.failed)}
                    note={`Access pauses after ${stats.graceDays} days`}
                    tone={stats.failed > 0 ? 'danger' : undefined}
                />
                <Kpi
                    label="Cancelled this month"
                    value={String(stats.cancelledThisMonth)}
                    note={stats.topReason ?? 'No reasons given'}
                />
            </section>

            <div className="grid items-start gap-3 sm:gap-4 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
                <Card className="flex flex-col gap-3 p-card">
                    <h2 className="text-base font-semibold sm:text-lg">
                        Plans
                    </h2>
                    {plans.map((plan) => (
                        <div
                            key={plan.id}
                            className={cn(
                                'flex items-center justify-between gap-3 rounded-lg border p-4',
                                plan.isFamily &&
                                    'border-secondary-70 bg-secondary-95 dark:border-secondary-30 dark:bg-secondary-20/40',
                            )}
                        >
                            <div className="min-w-0">
                                <p className="font-semibold">{plan.name}</p>
                                <p className="text-[0.8125rem] text-muted-foreground">
                                    {plan.detail}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                <p className="text-lg font-bold tabular-nums">
                                    {plan.subscribers}
                                </p>
                                <p className="text-[0.8125rem] text-muted-foreground">
                                    {plan.subscribers === 1
                                        ? 'subscriber'
                                        : 'subscribers'}
                                </p>
                            </div>
                        </div>
                    ))}
                </Card>

                <Card className="flex flex-col gap-1 p-card">
                    <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-base font-semibold sm:text-lg">
                            Needs attention
                        </h2>
                        <span className="text-[0.8125rem] text-muted-foreground">
                            Failed or overdue payments
                        </span>
                    </div>
                    {attention.length === 0 ? (
                        <p className="py-4 text-sm text-muted-foreground">
                            No failed payments. Everyone is up to date.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {attention.map((row) => (
                                <AttentionItem key={row.id} row={row} />
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <Card className="p-card">
                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-base font-semibold sm:text-lg">
                        Recent payments
                    </h2>
                    <Link
                        href={allPayments()}
                        className="text-sm font-semibold text-link hover:underline"
                    >
                        All payments
                    </Link>
                </div>
                {payments.length === 0 ? (
                    <p className="py-4 text-sm text-muted-foreground">
                        No payments yet.
                    </p>
                ) : (
                    <PaymentsTable payments={payments} />
                )}
            </Card>
        </AppLayout>
    );
}

function Kpi({
    label,
    value,
    note,
    tone,
}: {
    label: string;
    value: string;
    note: string;
    tone?: 'danger';
}) {
    return (
        <Card className="flex flex-col gap-1 p-card">
            <p className={smallCaps}>{label}</p>
            <p
                className={cn(
                    'text-stat font-bold tabular-nums',
                    tone === 'danger' && 'text-danger',
                )}
            >
                {value}
            </p>
            <p className="text-[0.8125rem] text-muted-foreground">{note}</p>
        </Card>
    );
}

function AttentionItem({ row }: { row: AttentionRow }) {
    const retriesLeft = row.canRetry && row.attempts < MAX_ATTEMPTS;

    return (
        <li className="flex flex-wrap items-center gap-3 py-3">
            <PersonAvatar
                name={row.payer}
                role="tutor"
                className="bg-primary-90 text-primary-10 dark:bg-primary-20 dark:text-primary-90"
            />
            <div className="min-w-0 flex-1 basis-48">
                <p className="truncate font-semibold">{row.payer}</p>
                <p className="text-[0.8125rem] text-muted-foreground">
                    {row.plan} · {row.problem}
                    {row.remindedAt && ` · emailed ${row.remindedAt}`}
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                <Form
                    {...remind.form(row.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="secondary"
                            size="sm"
                            disabled={processing || !row.canRemind}
                            title={
                                row.canRemind
                                    ? undefined
                                    : 'Emailed in the last day'
                            }
                        >
                            Email parent
                        </Button>
                    )}
                </Form>
                {row.processing ? (
                    <span className="inline-flex h-8 items-center gap-1.5 px-2 text-[0.8125rem] text-muted-foreground">
                        <Icon name="clock" className="size-4" />
                        Payment processing
                    </span>
                ) : retriesLeft ? (
                    <Form
                        {...retry.form(row.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                {processing ? 'Charging…' : 'Retry now'}
                            </Button>
                        )}
                    </Form>
                ) : (
                    <Form
                        {...pause.form(row.id)}
                        options={{ preserveScroll: true }}
                        onBefore={() =>
                            confirm(
                                `Pause quiz access for ${row.payer}'s ${row.plan} subscription now?`,
                            )
                        }
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                Pause access
                            </Button>
                        )}
                    </Form>
                )}
            </div>
        </li>
    );
}
