import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import type {
    DashboardFilterOptions,
    DashboardFilterValues,
} from '@/components/dashboard-filters';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import PersonAvatar from '@/components/person-avatar';
import { Badge } from '@/components/ui/badge';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { exportMethod as exportCsv, index } from '@/routes/admin/attendance';
import { show as showRegister } from '@/routes/lessons/register';
import type { Option, Paginated } from '@/types';

type Filters = Omit<DashboardFilterValues, 'tutor'> & {
    stage: string | null;
};

type Summary = {
    expected: number;
    present: number;
    absent: number;
    absentShare: number | null;
    withReason: number;
    late: number;
    lateStudents: number;
    lateTutors: number;
    averageMinutesLate: number | null;
};

type AttendanceRow = {
    id: number;
    name: string;
    role: 'student' | 'tutor';
    className: string;
    date: string;
    scheduled: string;
    arrived: string | null;
    status: AttendanceStatus;
    minutesLate: number | null;
};

type TodayLesson = { id: number; time: string; className: string };

type AttendanceProps = {
    filters: Filters;
    options: Omit<DashboardFilterOptions, 'tutors'> & { stages: Option[] };
    summary: Summary;
    records: Paginated<AttendanceRow>;
    todayLessons: TodayLesson[];
};

const audiences = [
    { value: null, label: 'All' },
    { value: 'student', label: 'Students' },
    { value: 'tutor', label: 'Tutors' },
] as const;

const smallCaps =
    'text-[0.6875rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

const plural = (count: number, word: string) =>
    `${count} ${word}${count === 1 ? '' : 's'}`;

/** The filters as a query string, without empty values or the derived labels. */
function query(filters: Filters, changes: Partial<Filters> = {}) {
    const next = { ...filters, ...changes };
    const values = {
        period: next.period,
        from: next.period === 'custom' ? next.from : null,
        to: next.period === 'custom' ? next.to : null,
        stage: next.stage,
        class: next.class,
        role: next.role,
    };

    return Object.fromEntries(
        Object.entries(values).filter(
            ([, value]) => value !== null && value !== '',
        ),
    ) as Record<string, string | number>;
}

export default function Attendance({
    filters,
    options,
    summary,
    records,
    todayLessons,
}: AttendanceProps) {
    const [period, setPeriod] = useState(filters.period);

    const apply = (changes: Partial<Filters>) =>
        router.get(
            index.url({ query: query(filters, changes) }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    return (
        <AppLayout
            title="Attendance and punctuality"
            actions={
                <div className="flex flex-wrap items-center gap-3">
                    <a
                        href={exportCsv.url({ query: query(filters) })}
                        className={buttonClasses('outline')}
                    >
                        <Icon name="download" className="size-4" />
                        Export CSV
                    </a>
                    <OpenRegister lessons={todayLessons} />
                </div>
            }
        >
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Every class, for students and tutors.
            </p>

            <Card className="flex flex-col gap-3 p-card">
                <div
                    role="group"
                    aria-label="Filter attendance"
                    className="grid grid-cols-2 items-end gap-3 sm:grid-cols-3 lg:flex lg:flex-wrap"
                >
                    <div className="col-span-2 flex flex-col gap-1.5 sm:col-span-1 lg:w-72">
                        <Label htmlFor="period">Date range</Label>
                        <Select
                            id="period"
                            value={period}
                            onChange={(event) => {
                                setPeriod(event.target.value);

                                if (event.target.value !== 'custom') {
                                    apply({ period: event.target.value });
                                }
                            }}
                        >
                            {options.periods.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                    {option.value === filters.period &&
                                    filters.period !== 'custom'
                                        ? ` (${filters.range})`
                                        : ''}
                                </option>
                            ))}
                        </Select>
                    </div>
                    {period === 'custom' && (
                        <CustomDates
                            filters={filters}
                            onApply={(from, to) =>
                                apply({ period: 'custom', from, to })
                            }
                        />
                    )}
                    <div className="flex flex-col gap-1.5 lg:w-48">
                        <Label htmlFor="stage">Stage</Label>
                        <Select
                            id="stage"
                            value={filters.stage ?? ''}
                            onChange={(event) =>
                                apply({ stage: event.target.value || null })
                            }
                        >
                            <option value="">All stages</option>
                            {options.stages.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 lg:w-56">
                        <Label htmlFor="class">Class</Label>
                        <Select
                            id="class"
                            value={filters.class ?? ''}
                            onChange={(event) =>
                                apply({
                                    class: event.target.value
                                        ? Number(event.target.value)
                                        : null,
                                })
                            }
                        >
                            <option value="">All classes</option>
                            {options.classes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <fieldset className="col-span-2 flex flex-col gap-1.5 sm:col-span-1 lg:ml-auto">
                        <legend className="mb-1.5 text-sm font-medium">
                            Show
                        </legend>
                        <div className="flex gap-1.5">
                            {audiences.map((audience) => {
                                const isActive =
                                    filters.role === audience.value;

                                return (
                                    <button
                                        key={audience.label}
                                        type="button"
                                        aria-pressed={isActive}
                                        onClick={() =>
                                            apply({ role: audience.value })
                                        }
                                        className={cn(
                                            'h-10 rounded-full border px-4 text-sm font-medium transition-colors sm:h-11',
                                            isActive
                                                ? 'border-foreground bg-foreground text-background'
                                                : 'bg-surface hover:bg-surface-muted',
                                        )}
                                    >
                                        {audience.label}
                                    </button>
                                );
                            })}
                        </div>
                    </fieldset>
                </div>
                <FieldError
                    id="period-error"
                    message={filters.error ?? undefined}
                />
            </Card>

            <section
                className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4"
                aria-label="Totals for the period"
            >
                <Total
                    label="Present"
                    value={String(summary.present)}
                    hint={`of ${summary.expected} expected ${summary.expected === 1 ? 'attendance' : 'attendances'}`}
                />
                <Total
                    label="Absent"
                    value={String(summary.absent)}
                    hint={
                        summary.absentShare === null
                            ? 'No registers taken yet'
                            : `${summary.absentShare}% · ${summary.withReason} with a reason given`
                    }
                />
                <Total
                    label="Late arrivals"
                    value={String(summary.late)}
                    hint={`${plural(summary.lateStudents, 'student')} · ${plural(summary.lateTutors, 'tutor')}`}
                />
                <Total
                    label="Average lateness"
                    value={
                        summary.averageMinutesLate === null
                            ? '—'
                            : `${summary.averageMinutesLate} min`
                    }
                    hint="Across all late arrivals"
                />
            </section>

            <Card className="p-card">
                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-base font-bold sm:text-lg">
                        Records · {records.data.length} shown
                    </h2>
                    <p className="text-xs text-muted-foreground sm:text-[0.8125rem]">
                        Arrival times are recorded when the register is taken.
                    </p>
                </div>

                {records.data.length === 0 ? (
                    <p className="py-6 text-sm text-muted-foreground">
                        No registers taken for these filters yet.
                    </p>
                ) : (
                    <RecordsTable records={records.data} />
                )}

                <nav
                    className="mt-4 flex items-center justify-between gap-3 text-sm"
                    aria-label="Pagination"
                >
                    <span className="text-muted-foreground">
                        Page {records.current_page} of{' '}
                        {Math.max(records.last_page, 1)}
                    </span>
                    <div className="flex gap-2">
                        <PageLink href={records.prev_page_url}>
                            Previous
                        </PageLink>
                        <PageLink href={records.next_page_url}>Next</PageLink>
                    </div>
                </nav>
            </Card>
        </AppLayout>
    );
}

function CustomDates({
    filters,
    onApply,
}: {
    filters: Filters;
    onApply: (from: string, to: string) => void;
}) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    return (
        <>
            <div className="flex flex-col gap-1.5 lg:w-40">
                <Label htmlFor="from">From</Label>
                <Input
                    id="from"
                    type="date"
                    value={from}
                    max={to}
                    onChange={(event) => setFrom(event.target.value)}
                />
            </div>
            <div className="flex flex-col gap-1.5 lg:w-40">
                <Label htmlFor="to">To</Label>
                <Input
                    id="to"
                    type="date"
                    value={to}
                    min={from}
                    onChange={(event) => setTo(event.target.value)}
                />
            </div>
            <Button
                type="button"
                variant="outline"
                disabled={!from || !to}
                onClick={() => onApply(from, to)}
            >
                Apply dates
            </Button>
        </>
    );
}

function Total({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <Card className="flex flex-col gap-1 p-card sm:gap-1.5">
            <p className={smallCaps}>{label}</p>
            <p className="text-stat tabular-nums">{value}</p>
            <p className="text-xs text-muted-foreground sm:text-[0.8125rem]">
                {hint}
            </p>
        </Card>
    );
}

function RecordsTable({ records }: { records: AttendanceRow[] }) {
    const heading = 'py-3 pr-4 font-semibold';

    return (
        <div className="relative -mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
            <table className="w-full min-w-[860px] text-left text-sm">
                <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                    <tr>
                        <th scope="col" className={heading}>
                            Name
                        </th>
                        <th scope="col" className={heading}>
                            Role
                        </th>
                        <th scope="col" className={heading}>
                            Class
                        </th>
                        <th scope="col" className={heading}>
                            Date
                        </th>
                        <th scope="col" className={heading}>
                            Scheduled
                        </th>
                        <th scope="col" className={heading}>
                            Arrived
                        </th>
                        <th scope="col" className={heading}>
                            Attendance
                        </th>
                        <th scope="col" className="py-3 font-semibold">
                            Punctuality
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {records.map((record) => (
                        <tr
                            key={record.id}
                            className="border-b align-middle last:border-0"
                        >
                            <td className="py-3 pr-4">
                                <span className="flex items-center gap-3 font-medium">
                                    <PersonAvatar
                                        name={record.name}
                                        role={record.role}
                                    />
                                    {record.name}
                                </span>
                            </td>
                            <td className="py-3 pr-4 text-muted-foreground">
                                {record.role === 'tutor' ? 'Tutor' : 'Student'}
                            </td>
                            <td className="py-3 pr-4">{record.className}</td>
                            <td className="py-3 pr-4 whitespace-nowrap text-muted-foreground">
                                {record.date}
                            </td>
                            <td className="py-3 pr-4 tabular-nums">
                                {record.scheduled}
                            </td>
                            <td className="py-3 pr-4 tabular-nums">
                                {record.arrived ?? (
                                    <span className="text-muted-foreground">
                                        <span aria-hidden="true">—</span>
                                        <span className="sr-only">
                                            Not recorded
                                        </span>
                                    </span>
                                )}
                            </td>
                            <td className="py-3 pr-4">
                                <AttendanceBadge status={record.status} />
                            </td>
                            <td className="py-3">
                                {(record.status === 'present' ||
                                    record.status === 'late') && (
                                    <AttendanceStatusBadge
                                        status={record.status}
                                        minutesLate={record.minutesLate}
                                    />
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Whether they came at all; how punctual they were has its own column. */
function AttendanceBadge({ status }: { status: AttendanceStatus }) {
    if (status === 'absent') {
        return (
            <Badge className="bg-absent text-white">
                <Icon name="cross" className="size-3.5" />
                Absent
            </Badge>
        );
    }

    if (status === 'excused') {
        return (
            <Badge tone="excused">
                <Icon name="info" className="size-3.5" />
                Excused
            </Badge>
        );
    }

    return (
        <Badge tone="brand">
            <Icon name="check" className="size-3.5" />
            Present
        </Badge>
    );
}

function PageLink({
    href,
    children,
}: {
    href: string | null;
    children: string;
}) {
    const classes = buttonClasses('outline', 'sm');

    return href ? (
        <Link href={href} preserveScroll className={classes}>
            {children}
        </Link>
    ) : (
        <span aria-disabled="true" className={cn(classes, 'opacity-50')}>
            {children}
        </span>
    );
}

/** Today's lessons, each opening its register. */
function OpenRegister({ lessons }: { lessons: TodayLesson[] }) {
    const [isOpen, setIsOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const close = (event: MouseEvent | KeyboardEvent) => {
            if (
                event instanceof KeyboardEvent
                    ? event.key === 'Escape'
                    : !container.current?.contains(event.target as Node)
            ) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);

        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [isOpen]);

    return (
        <div ref={container} className="relative">
            <Button
                type="button"
                aria-expanded={isOpen}
                aria-controls="today-registers"
                onClick={() => setIsOpen((open) => !open)}
            >
                <Icon name="list" className="size-4" />
                Open a register
            </Button>
            {isOpen && (
                <Card
                    id="today-registers"
                    className="absolute right-0 z-20 mt-2 w-72 p-2 shadow-lg"
                >
                    <p className={cn(smallCaps, 'px-2 pt-1 pb-2')}>
                        Today’s classes
                    </p>
                    {lessons.length === 0 ? (
                        <p className="px-2 pb-2 text-sm text-muted-foreground">
                            No classes today.
                        </p>
                    ) : (
                        <ul className="flex flex-col">
                            {lessons.map((lesson) => (
                                <li key={lesson.id}>
                                    <Link
                                        href={showRegister(lesson.id)}
                                        className="flex items-center justify-between gap-3 rounded-md px-2 py-2 text-sm hover:bg-surface-muted"
                                    >
                                        <span className="font-medium">
                                            {lesson.className}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {lesson.time}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}
        </div>
    );
}
