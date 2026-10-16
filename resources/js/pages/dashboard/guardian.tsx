import { Link } from '@inertiajs/react';
import FromAcademy from '@/components/from-academy';
import Icon from '@/components/icon';
import QuizAccessBadge from '@/components/quiz-access-badge';
import type { QuizAccess } from '@/components/quiz-access-badge';
import { strandAccents } from '@/components/strand-card';
import { Badge } from '@/components/ui/badge';
import { buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as billingIndex } from '@/routes/billing';
import { index as childrenIndex } from '@/routes/children';
import { acknowledge as acknowledgeNotice } from '@/routes/guardian-notices';
import type { Strand } from '@/types';

type ChildOverview = {
    id: number;
    name: string;
    yearGroup: string | null;
    access: QuizAccess & { note: string };
    attendance: {
        attendance: number | null;
        onTime: number | null;
        late: number;
        absent: number;
    };
    nextLesson: { className: string; when: string } | null;
    quizzes: {
        thisMonth: number;
        averagePercentage: number | null;
        strands: {
            key: Strand['key'];
            name: string;
            correct: number;
            total: number;
            percentage: number | null;
        }[];
        recent: {
            id: number;
            title: string;
            score: string;
            percentage: number;
            date: string;
        }[];
    };
};

type GuardianDashboardProps = {
    fromAcademy: {
        id: number;
        childName: string;
        when: string;
        note: string;
        sentBy: string | null;
    }[];
    children: ChildOverview[];
    /** Some plan can be bought today; until an admin links one, there's nothing to subscribe to. */
    paymentsAvailable: boolean;
};

const smallCaps =
    'text-[0.6875rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

const percent = (value: number | null) => (value === null ? '—' : `${value}%`);

export default function GuardianDashboard({
    fromAcademy,
    children,
    paymentsAvailable,
}: GuardianDashboardProps) {
    return (
        <AppLayout title="Your family" subtitle="Parent overview">
            <FromAcademy
                notes={fromAcademy.map((notice) => ({
                    id: notice.id,
                    heading: `${notice.childName} · ${notice.when}`,
                    note: notice.note,
                    from: notice.sentBy,
                }))}
                description="Notes about your children from the academy office."
                acknowledge={(id) => acknowledgeNotice.form(id)}
            />

            {children.length === 0 ? (
                <AddChildren />
            ) : (
                <>
                    {children.map((child) => (
                        <ChildCard
                            key={child.id}
                            child={child}
                            paymentsAvailable={paymentsAvailable}
                        />
                    ))}
                    <div className="flex flex-wrap gap-3">
                        <Link
                            href={childrenIndex()}
                            className={buttonClasses('outline')}
                        >
                            <Icon name="children" className="size-4" />
                            Manage children
                        </Link>
                        <Link
                            href={billingIndex()}
                            className={buttonClasses('outline')}
                        >
                            <Icon name="card" className="size-4" />
                            Billing
                        </Link>
                    </div>
                </>
            )}
        </AppLayout>
    );
}

function ChildCard({
    child,
    paymentsAvailable,
}: {
    child: ChildOverview;
    paymentsAvailable: boolean;
}) {
    const { attendance, quizzes } = child;
    const headingId = `child-${child.id}`;

    return (
        <section aria-labelledby={headingId}>
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-3 border-b px-4 py-3.5 sm:px-5 sm:py-4">
                    <div className="min-w-0">
                        <h2
                            id={headingId}
                            className="flex flex-wrap items-center gap-2 text-lg font-bold"
                        >
                            {child.name}
                            {child.yearGroup && (
                                <Badge tone="brand">{child.yearGroup}</Badge>
                            )}
                        </h2>
                        <p className="mt-1 text-[0.8125rem] text-muted-foreground">
                            {child.access.note}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <QuizAccessBadge
                            state={child.access.state}
                            paymentFailed={child.access.paymentFailed}
                        />
                        {(child.access.paymentFailed ||
                            (paymentsAvailable &&
                                child.access.state === 'none')) && (
                            <Link
                                href={billingIndex()}
                                className={buttonClasses('accent', 'sm')}
                            >
                                {child.access.paymentFailed
                                    ? 'Update card'
                                    : 'Subscribe'}
                            </Link>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-3 gap-px bg-border">
                    <Figure
                        label="Attendance"
                        value={percent(attendance.attendance)}
                        hint={
                            attendance.attendance === null
                                ? 'Once classes are marked'
                                : `${attendance.absent} absent in 30 days`
                        }
                    />
                    <Figure
                        label="On time"
                        value={percent(attendance.onTime)}
                        hint={
                            attendance.onTime === null
                                ? 'Once they’ve attended'
                                : `${attendance.late} late`
                        }
                    />
                    <Figure
                        label="Quizzes"
                        value={String(quizzes.thisMonth)}
                        hint={
                            quizzes.averagePercentage === null
                                ? 'Finished in 30 days'
                                : `Finished in 30 days · average ${quizzes.averagePercentage}%`
                        }
                    />
                </div>

                <div className="flex items-center gap-2 border-t px-4 py-3 text-sm sm:px-5">
                    <Icon
                        name="clock"
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                    {child.nextLesson ? (
                        <p>
                            Next class:{' '}
                            <span className="font-semibold">
                                {child.nextLesson.className}
                            </span>{' '}
                            · {child.nextLesson.when}
                        </p>
                    ) : (
                        <p className="text-muted-foreground">
                            No upcoming classes
                        </p>
                    )}
                </div>

                <div className="grid gap-5 border-t px-4 py-4 sm:px-5 md:grid-cols-2">
                    <div>
                        <h3 className={cn(smallCaps, 'mb-3')}>By topic</h3>
                        <ul className="flex flex-col gap-3">
                            {quizzes.strands.map((strand) => (
                                <li
                                    key={strand.key}
                                    className="flex flex-col gap-1.5"
                                >
                                    <div className="flex items-center justify-between gap-3 text-sm">
                                        <span className="flex items-center gap-2 font-medium">
                                            <span
                                                className={cn(
                                                    'size-2.5 rounded-full',
                                                    strandAccents[strand.key],
                                                )}
                                                aria-hidden="true"
                                            />
                                            {strand.name}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {strand.total === 0
                                                ? 'Not tried yet'
                                                : `${strand.correct}/${strand.total} right`}
                                        </span>
                                    </div>
                                    <div
                                        className="h-2 overflow-hidden rounded-full bg-surface-muted"
                                        role="img"
                                        aria-label={`${strand.name}: ${percent(strand.percentage)} correct`}
                                    >
                                        <div
                                            className={cn(
                                                'h-full rounded-full',
                                                strandAccents[strand.key],
                                            )}
                                            style={{
                                                width: `${strand.percentage ?? 0}%`,
                                            }}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div>
                        <h3 className={cn(smallCaps, 'mb-3')}>
                            Recent quizzes
                        </h3>
                        {quizzes.recent.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No quizzes finished yet.
                            </p>
                        ) : (
                            <ul className="flex flex-col divide-y">
                                {quizzes.recent.map((attempt) => (
                                    <li
                                        key={attempt.id}
                                        className="flex items-center justify-between gap-3 py-2 text-sm first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {attempt.title}
                                            </p>
                                            <p className="text-[0.8125rem] text-muted-foreground">
                                                {attempt.date}
                                            </p>
                                        </div>
                                        <span className="shrink-0 font-semibold tabular-nums">
                                            {attempt.score}{' '}
                                            <span className="font-normal text-muted-foreground">
                                                ({attempt.percentage}%)
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </Card>
        </section>
    );
}

function Figure({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <div className="flex flex-col gap-1 bg-surface px-3 py-3 sm:px-5 sm:py-4">
            <p className={smallCaps}>{label}</p>
            <p className="text-2xl font-bold tabular-nums sm:text-stat">
                {value}
            </p>
            <p className="text-xs text-muted-foreground sm:text-[0.8125rem]">
                {hint}
            </p>
        </div>
    );
}

function AddChildren() {
    return (
        <section className="relative flex flex-col gap-4 overflow-hidden rounded-lg bg-brand p-card text-brand-foreground sm:p-7">
            <span
                aria-hidden="true"
                className="absolute -top-8 -right-8 size-24 rounded-bl-full bg-secondary-key sm:-top-10 sm:-right-10 sm:size-40"
            />
            <span className="relative flex size-10 shrink-0 items-center justify-center rounded-md bg-secondary-key text-primary-10 sm:size-12">
                <Icon name="children" className="size-6" />
            </span>
            <div className="relative flex max-w-xl flex-col gap-2">
                <h2 className="text-section">Add your children</h2>
                <p className="text-body text-primary-90">
                    Add your children so they can log in. You’ll see their
                    attendance, punctuality and quiz progress here.
                </p>
            </div>
            <Link
                href={childrenIndex()}
                className={buttonClasses('accent', 'lg', 'relative self-start')}
            >
                Manage children
                <Icon name="arrow-right" className="size-4" />
            </Link>
        </section>
    );
}
