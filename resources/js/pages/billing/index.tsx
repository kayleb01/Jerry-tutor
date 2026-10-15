import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import type { BadgeTone } from '@/components/ui/badge';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { checkout } from '@/routes/billing';
import { cancel, card } from '@/routes/billing/subscriptions';
import { index as childrenIndex } from '@/routes/children';

type PlanOption = {
    id: number;
    name: string;
    description: string | null;
    amount: string;
    maxStudents: number;
};

type Child = {
    id: number;
    name: string;
    yearGroup: string | null;
    state: 'covered' | 'pending' | 'none';
    note: string;
};

type SubscriptionRow = {
    id: number;
    planName: string;
    students: string[];
    status: 'pending' | 'active' | 'past_due' | 'cancelled' | 'expired';
    statusLabel: string;
    note: string;
    card: string | null;
    gateway: string;
    canCancel: boolean;
    canUpdateCard: boolean;
};

type PaymentRow = {
    id: number;
    date: string;
    planName: string | null;
    amount: string;
    card: string | null;
    status: 'pending' | 'succeeded' | 'failed' | 'refunded';
    statusLabel: string;
    failureReason: string | null;
};

type Payer = {
    isStudent: boolean;
    needsEmail: boolean;
};

type BillingProps = {
    available: boolean;
    payer: Payer;
    plans: PlanOption[];
    children: Child[];
    subscriptions: SubscriptionRow[];
    payments: PaymentRow[];
    cancelReasons: string[];
};

const childTones: Record<Child['state'], BadgeTone> = {
    covered: 'present',
    pending: 'gold',
    none: 'neutral',
};

const childLabels: Record<Child['state'], string> = {
    covered: 'Quiz access',
    pending: 'Waiting',
    none: 'No access',
};

const subscriptionTones: Record<SubscriptionRow['status'], BadgeTone> = {
    pending: 'gold',
    active: 'present',
    past_due: 'absent',
    cancelled: 'neutral',
    expired: 'neutral',
};

const paymentTones: Record<PaymentRow['status'], BadgeTone> = {
    pending: 'gold',
    succeeded: 'present',
    failed: 'absent',
    refunded: 'neutral',
};

const smallCaps =
    'text-[0.6875rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

function list(names: string[]): string {
    return names.length <= 1
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

export default function Billing({
    available,
    payer,
    plans,
    children,
    subscriptions,
    payments,
    cancelReasons,
}: BillingProps) {
    const uncovered = children.filter((child) => child.state !== 'covered');

    return (
        <AppLayout title="Billing" subtitle="Quiz access">
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Monthly access to the quiz platform and exam preparation.
            </p>

            <section aria-labelledby="children-heading">
                <h2 id="children-heading" className={cn(smallCaps, 'mb-2')}>
                    {children.length === 1 ? 'Quiz access' : 'Your children'}
                </h2>
                {children.length === 0 ? (
                    <Card className="flex flex-wrap items-center justify-between gap-3 p-card text-sm">
                        <p>
                            Add your children first, then choose a plan for them
                            here.
                        </p>
                        <Link
                            href={childrenIndex()}
                            className={buttonClasses('outline', 'sm')}
                        >
                            Add children
                        </Link>
                    </Card>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {children.map((child) => (
                            <Card
                                key={child.id}
                                className="flex items-start justify-between gap-3 p-card"
                            >
                                <div className="min-w-0">
                                    <p className="truncate font-semibold">
                                        {child.name}
                                    </p>
                                    <p className="text-[0.8125rem] text-muted-foreground">
                                        {[child.yearGroup, child.note]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <Badge tone={childTones[child.state]}>
                                    {childLabels[child.state]}
                                </Badge>
                            </Card>
                        ))}
                    </div>
                )}
            </section>

            {children.length > 0 &&
                (available ? (
                    uncovered.length > 0 ? (
                        <SubscribeCard
                            plans={plans}
                            childList={children}
                            payer={payer}
                        />
                    ) : null
                ) : (
                    <Card className="flex items-center gap-3 p-card text-sm text-muted-foreground">
                        <Icon name="info" className="size-5 shrink-0" />
                        Payments aren’t available yet. Please check back soon.
                    </Card>
                ))}

            {subscriptions.length > 0 && (
                <section aria-labelledby="subscriptions-heading">
                    <h2
                        id="subscriptions-heading"
                        className={cn(smallCaps, 'mb-2')}
                    >
                        Your subscriptions
                    </h2>
                    <div className="flex flex-col gap-3">
                        {subscriptions.map((subscription) => (
                            <SubscriptionCard
                                key={subscription.id}
                                subscription={subscription}
                                cancelReasons={cancelReasons}
                            />
                        ))}
                    </div>
                </section>
            )}

            {payments.length > 0 && (
                <section aria-labelledby="payments-heading">
                    <h2 id="payments-heading" className={cn(smallCaps, 'mb-2')}>
                        Payment history
                    </h2>
                    <Card className="relative overflow-x-auto p-0">
                        <table className="w-full min-w-[560px] text-left text-sm">
                            <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-semibold sm:px-5"
                                    >
                                        Date
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-semibold"
                                    >
                                        Plan
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-semibold"
                                    >
                                        Amount
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-semibold"
                                    >
                                        Card
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-semibold sm:px-5"
                                    >
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {payments.map((payment) => (
                                    <tr
                                        key={payment.id}
                                        className="border-b align-top last:border-0"
                                    >
                                        <td className="px-4 py-3 whitespace-nowrap sm:px-5">
                                            {payment.date}
                                        </td>
                                        <td className="px-4 py-3">
                                            {payment.planName ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {payment.amount}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {payment.card ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 sm:px-5">
                                            <Badge
                                                tone={
                                                    paymentTones[payment.status]
                                                }
                                            >
                                                {payment.statusLabel}
                                            </Badge>
                                            {payment.failureReason && (
                                                <p className="mt-1 text-[0.8125rem] text-muted-foreground">
                                                    {payment.failureReason}
                                                </p>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                </section>
            )}
        </AppLayout>
    );
}

function SubscribeCard({
    plans,
    childList,
    payer,
}: {
    plans: PlanOption[];
    childList: Child[];
    payer: Payer;
}) {
    const [planId, setPlanId] = useState<number>(plans[0]?.id ?? 0);
    // A student only ever pays for themselves, so they're chosen from the start.
    const [chosen, setChosen] = useState<number[]>(
        payer.isStudent && childList[0] ? [childList[0].id] : [],
    );
    const plan = plans.find((option) => option.id === planId) ?? plans[0];
    const limit = plan?.maxStudents ?? 1;
    const chosenNames = childList
        .filter((child) => chosen.includes(child.id))
        .map((child) => child.name.split(' ')[0]);

    const choosePlan = (id: number) => {
        const next = plans.find((option) => option.id === id);
        setPlanId(id);
        setChosen((current) => current.slice(0, next?.maxStudents ?? 1));
    };

    const toggle = (id: number) =>
        setChosen((current) => {
            if (current.includes(id)) {
                return current.filter((value) => value !== id);
            }

            // A one-child plan swaps the choice; a family plan adds up to its limit.
            return limit === 1
                ? [id]
                : current.length < limit
                  ? [...current, id]
                  : current;
        });

    return (
        <Card className="p-card">
            <Form
                {...checkout.form()}
                className="flex flex-col gap-5"
                aria-label="Subscribe"
            >
                {({ errors, processing }) => (
                    <>
                        <div>
                            <h2 className="text-section font-bold">
                                Subscribe
                            </h2>
                            <p className="text-[0.8125rem] text-muted-foreground sm:text-sm">
                                Pay monthly by card. Cancel any time; access
                                runs to the end of the month you’ve paid for.
                            </p>
                        </div>

                        <fieldset>
                            <legend className={cn(smallCaps, 'mb-2')}>
                                1. Choose a plan
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {plans.map((option) => {
                                    const selected = option.id === planId;

                                    return (
                                        <label
                                            key={option.id}
                                            className={cn(
                                                'flex cursor-pointer items-start justify-between gap-3 rounded-lg border p-4 transition-colors',
                                                selected
                                                    ? option.maxStudents > 1
                                                        ? 'border-secondary-60 bg-secondary-95 dark:bg-secondary-20/40'
                                                        : 'border-primary bg-primary-95 dark:bg-primary-20/40'
                                                    : 'hover:bg-surface-muted',
                                            )}
                                        >
                                            <span className="flex items-start gap-3">
                                                <input
                                                    type="radio"
                                                    name="plan"
                                                    value={option.id}
                                                    checked={selected}
                                                    onChange={() =>
                                                        choosePlan(option.id)
                                                    }
                                                    className="mt-1 size-4 accent-primary"
                                                />
                                                <span>
                                                    <span className="block font-semibold">
                                                        {option.name}
                                                    </span>
                                                    <span className="block text-[0.8125rem] text-muted-foreground">
                                                        {payer.isStudent
                                                            ? 'Just you'
                                                            : option.maxStudents ===
                                                                1
                                                              ? 'One child'
                                                              : `Up to ${option.maxStudents} children`}
                                                        {option.description &&
                                                            ` · ${option.description}`}
                                                    </span>
                                                </span>
                                            </span>
                                            <span className="text-right">
                                                <span className="block text-lg font-bold tabular-nums">
                                                    {option.amount}
                                                </span>
                                                <span className="block text-[0.8125rem] text-muted-foreground">
                                                    a month
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>

                        {payer.isStudent ? (
                            <>
                                {chosen.map((id) => (
                                    <input
                                        key={id}
                                        type="hidden"
                                        name="students[]"
                                        value={id}
                                    />
                                ))}
                                <FieldError
                                    id="students-error"
                                    message={errors.students}
                                />
                            </>
                        ) : (
                            <fieldset aria-describedby="students-error">
                                <legend className={cn(smallCaps, 'mb-2')}>
                                    2.{' '}
                                    {limit === 1
                                        ? 'Choose a child'
                                        : `Choose up to ${limit} children`}
                                </legend>
                                <div className="flex flex-col gap-2">
                                    {childList.map((child) => {
                                        const covered =
                                            child.state === 'covered';
                                        const checked = chosen.includes(
                                            child.id,
                                        );
                                        const full =
                                            !checked &&
                                            limit > 1 &&
                                            chosen.length >= limit;

                                        return (
                                            <label
                                                key={child.id}
                                                className={cn(
                                                    'flex items-center gap-3 rounded-md border px-3.5 py-2.5 text-sm',
                                                    covered || full
                                                        ? 'cursor-not-allowed opacity-60'
                                                        : 'cursor-pointer hover:bg-surface-muted',
                                                )}
                                            >
                                                <input
                                                    type={
                                                        limit === 1
                                                            ? 'radio'
                                                            : 'checkbox'
                                                    }
                                                    name="students[]"
                                                    value={child.id}
                                                    checked={checked}
                                                    disabled={covered || full}
                                                    onChange={() =>
                                                        toggle(child.id)
                                                    }
                                                    className="size-4 accent-primary"
                                                />
                                                <span className="font-medium">
                                                    {child.name}
                                                </span>
                                                {covered && (
                                                    <span className="text-[0.8125rem] text-muted-foreground">
                                                        already has access
                                                    </span>
                                                )}
                                                {child.state === 'pending' && (
                                                    <span className="text-[0.8125rem] text-muted-foreground">
                                                        replaces the payment you
                                                        didn’t finish
                                                    </span>
                                                )}
                                            </label>
                                        );
                                    })}
                                </div>
                                <FieldError
                                    id="students-error"
                                    message={errors.students}
                                />
                            </fieldset>
                        )}

                        {payer.needsEmail && (
                            <div className="flex flex-col gap-1.5">
                                <Label
                                    htmlFor="billing-email"
                                    className={cn(smallCaps, 'mb-0.5')}
                                >
                                    2. Email for your receipts
                                </Label>
                                <Input
                                    id="billing-email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    placeholder="name@example.com"
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby="billing-email-hint billing-email-error"
                                    className="sm:max-w-sm"
                                />
                                <p
                                    id="billing-email-hint"
                                    className="text-[0.8125rem] text-muted-foreground"
                                >
                                    Your account doesn’t have an email address.
                                    The payment provider sends receipts here; a
                                    parent’s email is fine.
                                </p>
                                <FieldError
                                    id="billing-email-error"
                                    message={errors.email}
                                />
                            </div>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                            <p className="text-sm">
                                {chosen.length === 0 ? (
                                    <span className="text-muted-foreground">
                                        Choose who this is for.
                                    </span>
                                ) : (
                                    <>
                                        <span className="font-semibold">
                                            {plan?.amount} a month
                                        </span>{' '}
                                        {payer.isStudent
                                            ? 'for you'
                                            : `for ${list(chosenNames)}`}
                                    </>
                                )}
                            </p>
                            <Button
                                type="submit"
                                disabled={processing || chosen.length === 0}
                            >
                                <Icon name="lock" className="size-4" />
                                Continue to secure payment
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Card>
    );
}

function SubscriptionCard({
    subscription,
    cancelReasons,
}: {
    subscription: SubscriptionRow;
    cancelReasons: string[];
}) {
    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <Card className="flex flex-col gap-4 p-card">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-semibold">
                        {subscription.planName}
                        <span className="font-normal text-muted-foreground">
                            {' '}
                            · {list(subscription.students)}
                        </span>
                    </p>
                    <p
                        className={cn(
                            'text-[0.8125rem] sm:text-sm',
                            subscription.status === 'past_due'
                                ? 'font-semibold text-danger'
                                : 'text-muted-foreground',
                        )}
                    >
                        {subscription.note}
                    </p>
                    {subscription.card && (
                        <p className="mt-1 flex items-center gap-1.5 text-[0.8125rem] text-muted-foreground">
                            <Icon name="card" className="size-4" />
                            {subscription.card} · {subscription.gateway}
                        </p>
                    )}
                </div>
                <Badge tone={subscriptionTones[subscription.status]}>
                    {subscription.statusLabel}
                </Badge>
            </div>

            {(subscription.canUpdateCard || subscription.canCancel) &&
                !cancelling && (
                    <div className="flex flex-wrap gap-2">
                        {subscription.canUpdateCard && (
                            <Link
                                href={card(subscription.id)}
                                className={buttonClasses(
                                    subscription.status === 'past_due'
                                        ? 'primary'
                                        : 'outline',
                                    'sm',
                                )}
                            >
                                <Icon name="card" className="size-4" />
                                Update card
                            </Link>
                        )}
                        {subscription.canCancel && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setCancelling(true)}
                            >
                                Cancel subscription
                            </Button>
                        )}
                    </div>
                )}

            {cancelling && (
                <Form
                    {...cancel.form(subscription.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setCancelling(false)}
                    className="flex flex-col gap-3 rounded-md border p-3.5"
                >
                    {({ errors, processing }) => (
                        <>
                            <fieldset>
                                <legend className="mb-2 text-sm font-semibold">
                                    Why are you cancelling? (optional)
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {cancelReasons.map((option) => (
                                        <label
                                            key={option}
                                            className={cn(
                                                'cursor-pointer rounded-full border px-3 py-1.5 text-[0.8125rem] transition-colors has-[:focus-visible]:ring-3 has-[:focus-visible]:ring-ring/30',
                                                reason === option
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'hover:bg-surface-muted',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name={`reason-choice-${subscription.id}`}
                                                className="sr-only"
                                                checked={reason === option}
                                                onChange={() =>
                                                    setReason(option)
                                                }
                                            />
                                            {option}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            {reason === 'Something else' ? (
                                <div className="flex flex-col gap-1.5">
                                    <Label
                                        htmlFor={`reason-${subscription.id}`}
                                    >
                                        Tell us a little more
                                    </Label>
                                    <Textarea
                                        id={`reason-${subscription.id}`}
                                        name="reason"
                                        rows={2}
                                        maxLength={200}
                                        aria-describedby={`reason-${subscription.id}-error`}
                                    />
                                </div>
                            ) : (
                                reason && (
                                    <input
                                        type="hidden"
                                        name="reason"
                                        value={reason}
                                    />
                                )
                            )}
                            <FieldError
                                id={`reason-${subscription.id}-error`}
                                message={errors.reason}
                            />
                            <p className="text-[0.8125rem] text-muted-foreground">
                                {subscription.status === 'past_due'
                                    ? 'The last payment didn’t go through, so access ends when the short grace period does. It won’t renew.'
                                    : `${list(subscription.students)} keep${subscription.students.length === 1 ? 's' : ''} access until the end of the month you’ve paid for. It won’t renew after that.`}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    variant="danger"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Cancel subscription
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setCancelling(false)}
                                >
                                    Keep it
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </Card>
    );
}
