import { Form, InfiniteScroll, Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import LatenessReportDetail from '@/components/lateness-report-detail';
import type { LatenessDetail } from '@/components/lateness-report-detail';
import PersonAvatar from '@/components/person-avatar';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { exportMethod as exportCsv, index } from '@/routes/admin/lateness';
import type { Option } from '@/types';

type Row = {
    id: number;
    name: string;
    role: 'student' | 'tutor';
    className: string;
    date: string;
    scheduled: string;
    arrived: string;
    minutesLate: number;
    status: 'new' | 'followed';
    statusLabel: string;
};

type Filters = {
    status: string;
    role: 'student' | 'tutor' | null;
    class: number | null;
    from: string | null;
    to: string | null;
    search: string;
    report: number | null;
};

type LatenessProps = {
    reports: { data: Row[] };
    counts: Record<string, number>;
    filters: Filters;
    statuses: Option[];
    classes: Option[];
    selected: LatenessDetail | null;
};

const roles = [
    { value: null, label: 'Everyone' },
    { value: 'student', label: 'Students' },
    { value: 'tutor', label: 'Tutors' },
] as const;

const emptyMessages: Record<string, string> = {
    new: 'Every lateness report has been followed up.',
    followed: 'Nothing has been followed up yet.',
    all: 'No one has been late yet.',
};

const chipClass = (active: boolean) =>
    cn(
        'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-4 text-sm font-semibold whitespace-nowrap transition-colors',
        active
            ? 'border-primary bg-primary text-primary-foreground'
            : 'bg-surface text-foreground hover:bg-surface-muted',
    );

/** Builds a URL from the current filters, dropping empty and default values. */
function query(filters: Filters, overrides: Partial<Filters> = {}) {
    return Object.fromEntries(
        Object.entries({ ...filters, ...overrides }).filter(
            ([key, value]) =>
                value !== null &&
                value !== '' &&
                !(key === 'status' && value === 'new'),
        ),
    );
}

export default function LatenessInbox({
    reports,
    counts,
    filters,
    statuses,
    classes,
    selected,
}: LatenessProps) {
    const rows = reports.data;
    const position = selected
        ? rows.findIndex((row) => row.id === selected.id)
        : -1;
    const nextReportId =
        (position === -1
            ? rows.find((row) => row.id !== selected?.id)?.id
            : (rows[position + 1]?.id ?? rows[position - 1]?.id)) ?? null;
    const showingDetail = filters.report !== null;
    const isFiltered =
        filters.search !== '' ||
        filters.class !== null ||
        filters.from !== null ||
        filters.to !== null;

    return (
        <AppLayout
            title="Lateness reports"
            subtitle="Attendance"
            actions={
                <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                    <Form
                        {...index.form()}
                        className="min-w-0 flex-1 sm:w-64 sm:flex-none"
                        aria-label="Search lateness reports"
                    >
                        {Object.entries(
                            query(filters, { search: '', report: null }),
                        ).map(([name, value]) => (
                            <input
                                key={name}
                                type="hidden"
                                name={name}
                                value={String(value)}
                            />
                        ))}
                        <label className="relative block">
                            <span className="sr-only">Search by name</span>
                            <Icon
                                name="search"
                                className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                name="search"
                                type="search"
                                defaultValue={filters.search}
                                placeholder="Search by name"
                                className="pl-10 sm:pl-10"
                            />
                        </label>
                    </Form>
                    <a
                        href={exportCsv.url({
                            query: {
                                ...query(filters, { report: null }),
                                status: filters.status,
                            },
                        })}
                        className={buttonClasses('outline')}
                    >
                        <Icon name="download" className="size-4" />
                        Export
                    </a>
                </div>
            }
        >
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Created automatically whenever a student or a tutor is late.
            </p>

            <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
                <nav aria-label="Follow-up status" className="flex gap-2">
                    {statuses.map((status) => (
                        <Link
                            key={status.value}
                            href={index({
                                query: query(filters, {
                                    status: String(status.value),
                                    report: null,
                                }),
                            })}
                            preserveScroll
                            aria-current={
                                filters.status === status.value
                                    ? 'page'
                                    : undefined
                            }
                            className={chipClass(
                                filters.status === status.value,
                            )}
                        >
                            {status.label}
                            <span className="tabular-nums opacity-75">
                                · {counts[status.value] ?? 0}
                            </span>
                        </Link>
                    ))}
                </nav>
                <span
                    aria-hidden="true"
                    className="mx-1 w-px shrink-0 bg-border"
                />
                <nav aria-label="Who" className="flex gap-2">
                    {roles.map((role) => (
                        <Link
                            key={role.label}
                            href={index({
                                query: query(filters, {
                                    role: role.value,
                                    report: null,
                                }),
                            })}
                            preserveScroll
                            aria-current={
                                filters.role === role.value ? 'page' : undefined
                            }
                            className={chipClass(filters.role === role.value)}
                        >
                            {role.label}
                        </Link>
                    ))}
                </nav>
            </div>

            <Form
                {...index.form()}
                className={cn(
                    'grid grid-cols-2 items-end gap-3 sm:grid-cols-4 lg:flex lg:flex-wrap',
                    showingDetail && 'hidden lg:flex',
                )}
                aria-label="Filter lateness reports"
            >
                <input type="hidden" name="status" value={filters.status} />
                {filters.role && (
                    <input type="hidden" name="role" value={filters.role} />
                )}
                {filters.search && (
                    <input type="hidden" name="search" value={filters.search} />
                )}
                <div className="col-span-2 flex flex-col gap-1.5 sm:col-span-2 lg:w-52">
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
                            href={index({
                                query: query(filters, {
                                    search: '',
                                    class: null,
                                    from: null,
                                    to: null,
                                    report: null,
                                }),
                            })}
                            className="text-sm font-semibold text-link hover:underline"
                        >
                            Reset
                        </Link>
                    )}
                </div>
            </Form>

            <div className="grid items-start gap-3 sm:gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
                <Card
                    className={cn(
                        'overflow-hidden lg:sticky lg:top-6 lg:max-h-[calc(100dvh-3rem)] lg:overflow-y-auto',
                        showingDetail && 'hidden lg:block',
                    )}
                >
                    {rows.length === 0 ? (
                        <p className="p-card text-sm text-muted-foreground">
                            {isFiltered || filters.role
                                ? 'No lateness reports match these filters.'
                                : emptyMessages[filters.status]}
                        </p>
                    ) : (
                        <InfiniteScroll
                            data="reports"
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
                                                : 'Load more reports'}
                                        </Button>
                                    </div>
                                )
                            }
                        >
                            {rows.map((row) => (
                                <ReportRow
                                    key={row.id}
                                    row={row}
                                    active={row.id === selected?.id}
                                    href={index({
                                        query: query(filters, {
                                            report: row.id,
                                        }),
                                    })}
                                />
                            ))}
                        </InfiniteScroll>
                    )}
                </Card>

                <div className={cn(!showingDetail && 'hidden lg:block')}>
                    {showingDetail && (
                        <Link
                            href={index({
                                query: query(filters, { report: null }),
                            })}
                            className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-link lg:hidden"
                        >
                            <Icon name="arrow-left" className="size-4" />
                            All reports
                        </Link>
                    )}
                    {selected ? (
                        <LatenessReportDetail
                            key={selected.id}
                            report={selected}
                            nextReportId={nextReportId}
                        />
                    ) : (
                        <Card className="p-card text-sm text-muted-foreground">
                            Choose a report to follow it up.
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function ReportRow({
    row,
    active,
    href,
}: {
    row: Row;
    active: boolean;
    href: ReturnType<typeof index>;
}) {
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
                'flex items-center gap-3 border-b border-l-4 px-4 py-3.5 transition-colors last:border-b-0 sm:px-5',
                active
                    ? 'border-l-primary bg-primary-95 dark:bg-primary-20/50'
                    : 'border-l-transparent hover:bg-surface-muted',
            )}
        >
            <PersonAvatar name={row.name} role={row.role} />
            <span className="min-w-0 flex-1">
                <span className="block truncate">
                    <span className="font-semibold">{row.name}</span>
                    <span className="text-muted-foreground">
                        {' '}
                        · {row.role === 'tutor' ? 'Tutor' : 'Student'}
                    </span>
                </span>
                <span className="block truncate text-[13px] text-muted-foreground">
                    {row.className} · {row.date}
                </span>
                <span className="block text-[13px] text-muted-foreground 2xl:hidden">
                    Due {row.scheduled} · in {row.arrived}
                </span>
            </span>
            <span className="hidden shrink-0 text-[13px] text-muted-foreground tabular-nums 2xl:block">
                Due {row.scheduled} · in {row.arrived}
            </span>
            <span className="inline-flex h-7 shrink-0 items-center rounded-md bg-late-soft px-2 text-xs font-semibold text-late tabular-nums">
                {row.minutesLate} min
            </span>
            <span
                className={cn(
                    'hidden w-24 shrink-0 text-right text-sm md:block',
                    row.status === 'new'
                        ? 'font-semibold text-late'
                        : 'text-muted-foreground',
                )}
            >
                {row.statusLabel}
            </span>
        </Link>
    );
}
