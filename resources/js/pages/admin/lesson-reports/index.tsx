import { Form, InfiniteScroll, Link } from '@inertiajs/react';
import ClassReportDetail from '@/components/class-report-detail';
import type { ClassReportLesson } from '@/components/class-report-detail';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/lesson-reports';
import type { Option } from '@/types';

type Row = {
    id: number;
    className: string;
    tutor: string | null;
    day: string;
    time: string;
    state: string;
    stateLabel: string;
};

type Filters = {
    queue: string;
    search: string;
    class: number | null;
    tutor: number | null;
    from: string | null;
    to: string | null;
    lesson: number | null;
};

type LessonReportsProps = {
    lessons: { data: Row[] };
    counts: Record<string, number>;
    filters: Filters;
    queues: Option[];
    classes: Option[];
    tutors: Option[];
    selected: ClassReportLesson | null;
};

const states: Record<string, { icon: IconName; className: string }> = {
    both: { icon: 'check', className: 'text-link' },
    pending: { icon: 'clock', className: 'text-muted-foreground' },
    missing: { icon: 'alarm', className: 'text-late' },
    raised: { icon: 'flag', className: 'text-danger' },
    reviewed: { icon: 'check', className: 'text-muted-foreground' },
};

const emptyMessages: Record<string, string> = {
    review: 'Nothing waiting for review. Nice work.',
    raised: 'No open issues with tutors.',
    missing: 'Every class that ran has a tutor report.',
    reviewed: 'No reviewed classes yet.',
    all: 'No classes have run yet.',
};

/** Builds an inbox URL from the current filters, dropping empty values. */
function inboxUrl(filters: Filters, overrides: Partial<Filters> = {}) {
    const query = Object.fromEntries(
        Object.entries({ ...filters, ...overrides }).filter(
            ([key, value]) =>
                value !== null &&
                value !== '' &&
                !(key === 'queue' && value === 'review'),
        ),
    );

    return index({ query });
}

export default function LessonReportsInbox({
    lessons,
    counts,
    filters,
    queues,
    classes,
    tutors,
    selected,
}: LessonReportsProps) {
    const rows = lessons.data;
    const position = selected
        ? rows.findIndex((row) => row.id === selected.id)
        : -1;
    const nextLessonId =
        (position === -1
            ? rows.find((row) => row.id !== selected?.id)?.id
            : (rows[position + 1]?.id ?? rows[position - 1]?.id)) ?? null;
    const showingDetail = filters.lesson !== null;
    const isFiltered =
        filters.search !== '' ||
        filters.class !== null ||
        filters.tutor !== null ||
        filters.from !== null ||
        filters.to !== null;

    return (
        <AppLayout
            title="End-of-class reports"
            subtitle="Attendance"
            actions={
                <Form
                    {...index.form()}
                    className="w-full sm:w-72"
                    aria-label="Search class reports"
                >
                    <input type="hidden" name="queue" value={filters.queue} />
                    {filters.class && (
                        <input
                            type="hidden"
                            name="class"
                            value={filters.class}
                        />
                    )}
                    {filters.tutor && (
                        <input
                            type="hidden"
                            name="tutor"
                            value={filters.tutor}
                        />
                    )}
                    {filters.from && (
                        <input type="hidden" name="from" value={filters.from} />
                    )}
                    {filters.to && (
                        <input type="hidden" name="to" value={filters.to} />
                    )}
                    <label className="relative block">
                        <span className="sr-only">
                            Search classes or people
                        </span>
                        <Icon
                            name="search"
                            className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            name="search"
                            type="search"
                            defaultValue={filters.search}
                            placeholder="Search classes or people"
                            className="pl-10 sm:pl-10"
                        />
                    </label>
                </Form>
            }
        >
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Feedback and recommendations from both sides of every class.
            </p>

            <nav
                aria-label="Report queues"
                className="-mx-4 flex gap-1 overflow-x-auto px-4 sm:mx-0 sm:px-0"
            >
                {queues.map((queue) => {
                    const active = filters.queue === queue.value;

                    return (
                        <Link
                            key={queue.value}
                            href={inboxUrl(filters, {
                                queue: String(queue.value),
                                lesson: null,
                            })}
                            preserveScroll
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'inline-flex h-9 shrink-0 items-center gap-2 rounded-md px-3 text-sm font-semibold whitespace-nowrap',
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-surface-muted hover:text-foreground',
                            )}
                        >
                            {queue.label}
                            <span className="tabular-nums opacity-75">
                                {counts[queue.value] ?? 0}
                            </span>
                        </Link>
                    );
                })}
            </nav>

            <Form
                {...index.form()}
                className={cn(
                    'grid grid-cols-2 items-end gap-3 sm:grid-cols-4 lg:flex lg:flex-wrap',
                    showingDetail && 'hidden lg:flex',
                )}
                aria-label="Filter class reports"
            >
                <input type="hidden" name="queue" value={filters.queue} />
                {filters.search && (
                    <input type="hidden" name="search" value={filters.search} />
                )}
                <div className="flex flex-col gap-1.5 lg:w-48">
                    <Label htmlFor="class">Class</Label>
                    <Select
                        id="class"
                        name="class"
                        defaultValue={filters.class ?? ''}
                    >
                        <option value="">All classes</option>
                        {classes.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="flex flex-col gap-1.5 lg:w-44">
                    <Label htmlFor="tutor">Tutor</Label>
                    <Select
                        id="tutor"
                        name="tutor"
                        defaultValue={filters.tutor ?? ''}
                    >
                        <option value="">All tutors</option>
                        {tutors.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="flex flex-col gap-1.5 lg:w-40">
                    <Label htmlFor="from">From</Label>
                    <Input
                        id="from"
                        name="from"
                        type="date"
                        defaultValue={filters.from ?? ''}
                    />
                </div>
                <div className="flex flex-col gap-1.5 lg:w-40">
                    <Label htmlFor="to">To</Label>
                    <Input
                        id="to"
                        name="to"
                        type="date"
                        defaultValue={filters.to ?? ''}
                    />
                </div>
                <div className="col-span-2 flex items-center gap-3 sm:col-span-4 lg:col-span-1">
                    <Button type="submit" variant="outline">
                        <Icon name="filter" className="size-4" />
                        Apply
                    </Button>
                    {isFiltered && (
                        <Link
                            href={inboxUrl({
                                ...filters,
                                search: '',
                                class: null,
                                tutor: null,
                                from: null,
                                to: null,
                                lesson: null,
                            })}
                            className="text-sm font-semibold text-link hover:underline"
                        >
                            Reset
                        </Link>
                    )}
                </div>
            </Form>

            <div className="grid items-start gap-3 sm:gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                <Card
                    className={cn(
                        'overflow-hidden lg:sticky lg:top-6 lg:max-h-[calc(100dvh-3rem)] lg:overflow-y-auto',
                        showingDetail && 'hidden lg:block',
                    )}
                >
                    <p className="px-5 pt-5 pb-2 text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs">
                        Recent classes
                    </p>
                    {rows.length === 0 ? (
                        <p className="px-5 pb-6 text-sm text-muted-foreground">
                            {isFiltered
                                ? 'No classes match these filters.'
                                : emptyMessages[filters.queue]}
                        </p>
                    ) : (
                        <InfiniteScroll
                            data="lessons"
                            manual
                            preserveUrl
                            next={({ loading, fetch, hasMore }) =>
                                hasMore && (
                                    <div className="px-5 pt-2 pb-5">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                            onClick={fetch}
                                            disabled={loading}
                                        >
                                            {loading
                                                ? 'Loading…'
                                                : 'Load more classes'}
                                        </Button>
                                    </div>
                                )
                            }
                        >
                            {rows.map((row, i) => (
                                <div key={row.id}>
                                    {row.day !== rows[i - 1]?.day && (
                                        <p className="bg-surface-muted/60 px-5 py-1.5 text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                            {row.day}
                                        </p>
                                    )}
                                    <InboxRow
                                        row={row}
                                        active={row.id === selected?.id}
                                        href={inboxUrl(filters, {
                                            lesson: row.id,
                                        })}
                                    />
                                </div>
                            ))}
                        </InfiniteScroll>
                    )}
                </Card>

                <div className={cn(!showingDetail && 'hidden lg:block')}>
                    {showingDetail && (
                        <Link
                            href={inboxUrl(filters, { lesson: null })}
                            className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-link lg:hidden"
                        >
                            <Icon name="arrow-left" className="size-4" />
                            All classes
                        </Link>
                    )}
                    {selected ? (
                        <ClassReportDetail
                            key={selected.id}
                            lesson={selected}
                            nextLessonId={nextLessonId}
                        />
                    ) : (
                        <Card className="p-card text-sm text-muted-foreground">
                            Choose a class to read its reports.
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function InboxRow({
    row,
    active,
    href,
}: {
    row: Row;
    active: boolean;
    href: ReturnType<typeof index>;
}) {
    const state = states[row.state] ?? {
        icon: 'report' as IconName,
        className: 'text-muted-foreground',
    };

    return (
        <Link
            href={href}
            only={['selected', 'filters']}
            // Below lg the list is replaced by the detail, so start it at the top.
            preserveScroll={() =>
                window.matchMedia('(min-width: 64rem)').matches
            }
            preserveState
            aria-current={active ? 'true' : undefined}
            className={cn(
                'block border-l-4 px-4 py-3.5 transition-colors sm:px-5',
                active
                    ? 'border-primary bg-primary-95 dark:bg-primary-20/50'
                    : 'border-transparent hover:bg-surface-muted',
            )}
        >
            <span className="flex items-baseline justify-between gap-3">
                <span className="truncate font-semibold">{row.className}</span>
                <span className="shrink-0 text-[13px] text-muted-foreground tabular-nums">
                    {row.time}
                </span>
            </span>
            {row.tutor && (
                <span className="block truncate text-[13px] text-muted-foreground sm:text-sm">
                    {row.tutor}
                </span>
            )}
            <span
                className={cn(
                    'mt-1 flex items-center gap-1.5 text-[13px] font-semibold',
                    state.className,
                )}
            >
                <Icon name={state.icon} className="size-3.5" />
                {row.stateLabel}
            </span>
        </Link>
    );
}
