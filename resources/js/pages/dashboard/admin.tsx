import { Form, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import DashboardFilters from '@/components/dashboard-filters';
import type {
    DashboardFilterOptions,
    DashboardFilterValues,
} from '@/components/dashboard-filters';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import Icon from '@/components/icon';
import PersonAvatar from '@/components/person-avatar';
import type { IconName } from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { create as createClass } from '@/routes/admin/classes';
import { index as latenessIndex } from '@/routes/admin/lateness';
import { index as lessonReportsIndex } from '@/routes/admin/lesson-reports';
import { index as questionsIndex } from '@/routes/admin/questions';
import { index as usersIndex } from '@/routes/admin/users';
import { show as register } from '@/routes/lessons/register';
import type { LessonState } from '@/types';

type Role = 'student' | 'tutor';

type TodayLesson = {
    id: number;
    time: string;
    className: string;
    tutor: string | null;
    state: LessonState;
    tutorStatus: AttendanceStatus | null;
    tutorMinutesLate: number | null;
    present: number;
    marked: boolean;
    expected: number;
    tutorReported: boolean;
    studentReports: number;
};

type AdminDashboardProps = {
    header: { date: string; greeting: string };
    filters: DashboardFilterValues;
    options: DashboardFilterOptions;
    stats: {
        classesToday: number;
        runningNow: number;
        toStart: number;
        attendanceRole: Role;
        attendance: number | null;
        attendanceChange: number | null;
        late: number;
        lateStudents: number;
        lateTutors: number;
        activeStudents: number;
        activeTutors: number;
    };
    today: TodayLesson[];
    lateness: {
        id: number;
        name: string;
        role: Role;
        className: string;
        date: string;
        arrived: string;
        minutesLate: number;
    }[];
    repeatLateness: {
        userId: number;
        name: string;
        role: Role;
        count: number;
        lastDate: string;
    }[];
    trend: {
        label: string;
        buckets: {
            label: string;
            students: number | null;
            tutors: number | null;
        }[];
    };
    latestReports: {
        id: number;
        author: string;
        role: Role;
        className: string;
        submittedAt: string;
        wentWell: string | null;
        recommendations: string | null;
    }[];
    quizzes: {
        categories: { label: string; count: number }[];
        questionBank: number;
        available: boolean;
    };
};

const roleLabel = (role: Role) => (role === 'tutor' ? 'Tutor' : 'Student');

function Avatar({ name, role }: { name: string; role: Role }) {
    return <PersonAvatar name={name} role={role} />;
}

function Kpi({
    label,
    value,
    note,
    icon,
    gold = false,
}: {
    label: string;
    value: ReactNode;
    note: string;
    icon: IconName;
    gold?: boolean;
}) {
    return (
        <Card className="flex flex-col gap-1 p-card sm:gap-1.5">
            <div className="flex items-center justify-between gap-3">
                <p className="text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs">
                    {label}
                </p>
                <span
                    className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-md sm:size-10',
                        gold
                            ? 'bg-secondary-95 text-secondary-40 dark:bg-secondary-20 dark:text-secondary-90'
                            : 'bg-primary-95 text-primary-30 dark:bg-primary-20 dark:text-primary-90',
                    )}
                >
                    <Icon name={icon} />
                </span>
            </div>
            <p className="text-stat tabular-nums">{value}</p>
            <p className="text-xs text-muted-foreground sm:text-[13px]">
                {note}
            </p>
        </Card>
    );
}

function SectionHead({
    title,
    hint,
    action,
}: {
    title: string;
    hint?: string;
    action?: ReactNode;
}) {
    return (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3 sm:mb-4">
            <div>
                <h2 className="text-base font-bold sm:text-lg">{title}</h2>
                {hint && (
                    <p className="text-xs text-muted-foreground sm:text-[13px]">
                        {hint}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}

function TutorPunctuality({ lesson }: { lesson: TodayLesson }) {
    if (lesson.tutorStatus) {
        return (
            <AttendanceStatusBadge
                status={lesson.tutorStatus}
                minutesLate={lesson.tutorMinutesLate}
            />
        );
    }

    if (lesson.state === 'cancelled') {
        return <Badge tone="absent">Cancelled</Badge>;
    }

    return (
        <span className="text-[13px] text-muted-foreground">
            {lesson.state === 'upcoming' ? 'Not started' : 'Not checked in'}
        </span>
    );
}

function ReportState({ lesson }: { lesson: TodayLesson }) {
    if (lesson.state === 'upcoming' || lesson.state === 'cancelled') {
        return <span className="text-[13px] text-muted-foreground">—</span>;
    }

    if (lesson.state === 'in_progress') {
        return (
            <span className="text-[13px] text-muted-foreground">
                In progress
            </span>
        );
    }

    if (lesson.tutorReported) {
        return (
            <span className="text-[13px] font-semibold text-primary-30 dark:text-primary-80">
                {lesson.studentReports > 0
                    ? 'Both submitted'
                    : 'Tutor submitted'}
            </span>
        );
    }

    return (
        <span className="text-[13px] text-muted-foreground">Tutor pending</span>
    );
}

const comparisonLabel: Record<string, string> = {
    today: 'yesterday',
    week: 'the same point last week',
    month: 'the same point last month',
};

function percentChange(
    change: number | null,
    filters: DashboardFilterValues,
): string {
    const against = comparisonLabel[filters.period] ?? 'the period before';

    if (change === null) {
        return `${filters.label} · nothing to compare with yet`;
    }

    if (change === 0) {
        return `${filters.label} · same as ${against}`;
    }

    return `${filters.label} · ${change > 0 ? 'up' : 'down'} ${Math.abs(change)} ${Math.abs(change) === 1 ? 'point' : 'points'} on ${against}`;
}

const BAR_HEIGHT = 150;

function OnTimeChart({
    weeks,
}: {
    weeks: AdminDashboardProps['trend']['buckets'];
}) {
    const describe = (value: number | null) =>
        value === null ? 'no data' : `${value}%`;
    const summary = weeks
        .map(
            (week) =>
                `${week.label}: students ${describe(week.students)}, tutors ${describe(week.tutors)}`,
        )
        .join('; ');

    const dense = weeks.length > 8;
    const columns = {
        gridTemplateColumns: `repeat(${weeks.length}, minmax(0, 1fr))`,
    };

    const bar = (value: number | null, tone: string) => (
        <div className="flex h-full flex-col items-center justify-end gap-1">
            {!dense && (
                <span className="text-[11px] text-muted-foreground tabular-nums">
                    {value === null ? '—' : value}
                </span>
            )}
            {value !== null && (
                <span
                    className={cn(
                        'block rounded-t',
                        dense ? 'w-1.5 sm:w-2' : 'w-3 sm:w-[18px]',
                        tone,
                    )}
                    style={{
                        height: `${Math.max(2, (value / 100) * BAR_HEIGHT)}px`,
                    }}
                />
            )}
        </div>
    );

    return (
        <>
            <div
                role="img"
                aria-label={`On-time arrivals. ${summary}.`}
                className="grid h-[200px] items-end gap-1 border-b border-neutral-80 pt-2 sm:gap-3 dark:border-neutral-30"
                style={columns}
            >
                {weeks.map((week) => (
                    <div
                        key={week.label}
                        className="flex h-full items-end justify-center gap-1"
                    >
                        {bar(week.students, 'bg-brand dark:bg-primary-70')}
                        {bar(week.tutors, 'bg-secondary-key')}
                    </div>
                ))}
            </div>
            <div
                className="mt-2 grid gap-1 text-center text-[11px] text-muted-foreground sm:gap-3 sm:text-[13px]"
                style={columns}
            >
                {weeks.map((week, index) => (
                    <span
                        key={week.label}
                        className={cn(
                            'whitespace-nowrap',
                            dense && index % 2 === 1 && 'invisible',
                        )}
                    >
                        {week.label}
                    </span>
                ))}
            </div>
        </>
    );
}

export default function AdminDashboard({
    header,
    filters,
    options,
    stats,
    today,
    lateness,
    repeatLateness,
    trend,
    latestReports,
    quizzes,
}: AdminDashboardProps) {
    const repeat = repeatLateness[0];
    const largestQuizCategory = Math.max(
        1,
        ...quizzes.categories.map((category) => category.count),
    );
    const periodLabel = filters.label.toLowerCase();

    return (
        <AppLayout
            title={header.greeting}
            subtitle={header.date}
            actions={
                <>
                    <Form
                        {...usersIndex.form()}
                        className="w-[280px] max-w-full"
                    >
                        <label className="flex h-10 items-center gap-2 rounded-sm border border-neutral-80 bg-surface-muted px-3 text-muted-foreground dark:border-neutral-30">
                            <Icon name="search" />
                            <input
                                type="search"
                                name="search"
                                placeholder="Search students, tutors, parents"
                                aria-label="Search users"
                                className="min-w-0 flex-1 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
                            />
                        </label>
                    </Form>
                    <Link href={createClass()} className={buttonClasses()}>
                        <Icon name="plus" className="size-4" />
                        Schedule class
                    </Link>
                </>
            }
        >
            <DashboardFilters filters={filters} options={options} />

            <section
                aria-label="At a glance"
                className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4"
            >
                <Kpi
                    label="Classes today"
                    value={stats.classesToday}
                    note={`${stats.runningNow} running now · ${stats.toStart} still to start`}
                    icon="classes"
                />
                <Kpi
                    label={`${roleLabel(stats.attendanceRole)} attendance`}
                    value={
                        stats.attendance === null ? '—' : `${stats.attendance}%`
                    }
                    note={percentChange(stats.attendanceChange, filters)}
                    icon="check"
                />
                <Kpi
                    label="Late arrivals"
                    value={stats.late}
                    note={
                        stats.late === 0
                            ? `No late arrivals · ${periodLabel}`
                            : `${stats.lateStudents} ${stats.lateStudents === 1 ? 'student' : 'students'} · ${stats.lateTutors} ${stats.lateTutors === 1 ? 'tutor' : 'tutors'} · reports created`
                    }
                    icon="alarm"
                    gold
                />
                <Kpi
                    label="Active students"
                    value={stats.activeStudents}
                    note={`${stats.activeTutors} ${stats.activeTutors === 1 ? 'tutor' : 'tutors'} on the team`}
                    icon="users"
                />
            </section>

            <div className="flex flex-wrap items-start gap-3 sm:gap-4">
                <Card className="flex-[999_1_720px] p-card">
                    <SectionHead
                        title="Today’s classes"
                        hint="Select a class to open its register."
                    />
                    {today.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            No classes are scheduled today.
                        </p>
                    ) : (
                        <div className="-mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
                            <table className="w-full min-w-[640px] border-collapse text-left text-sm">
                                <thead>
                                    <tr className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                                        {[
                                            'Time',
                                            'Class',
                                            'Tutor',
                                            'Tutor punctuality',
                                            'Present',
                                            'Reports',
                                        ].map((heading) => (
                                            <th
                                                key={heading}
                                                scope="col"
                                                className="px-3 py-2.5 font-semibold whitespace-nowrap"
                                            >
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {today.map((lesson) => (
                                        <tr
                                            key={lesson.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-3 py-3.5 tabular-nums">
                                                {lesson.time}
                                            </td>
                                            <td className="px-3 py-3.5">
                                                <Link
                                                    href={register(lesson.id)}
                                                    className="font-semibold hover:underline"
                                                >
                                                    {lesson.className}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-3.5">
                                                {lesson.tutor ?? '—'}
                                            </td>
                                            <td className="px-3 py-3.5">
                                                <TutorPunctuality
                                                    lesson={lesson}
                                                />
                                            </td>
                                            <td className="px-3 py-3.5 tabular-nums">
                                                {lesson.marked
                                                    ? lesson.present
                                                    : '—'}{' '}
                                                / {lesson.expected}
                                            </td>
                                            <td className="px-3 py-3.5">
                                                <ReportState lesson={lesson} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <Card className="flex-[1_1_320px] p-card">
                    <SectionHead
                        title={`Lateness · ${filters.label}`}
                        action={
                            <Link
                                href={latenessIndex()}
                                className="text-sm font-semibold text-link hover:underline"
                            >
                                View all
                            </Link>
                        }
                    />
                    {lateness.length === 0 ? (
                        <p className="py-4 text-sm text-muted-foreground">
                            No one has been late{' '}
                            {periodLabel === 'today'
                                ? 'today'
                                : `in ${periodLabel}`}
                            .
                        </p>
                    ) : (
                        <ul className="flex flex-col">
                            {lateness.map((report) => (
                                <li
                                    key={report.id}
                                    className="flex items-center justify-between gap-3 border-b py-3 last:border-0"
                                >
                                    <div className="flex min-w-0 items-center gap-3">
                                        <Avatar
                                            name={report.name}
                                            role={report.role}
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold">
                                                {report.name}{' '}
                                                <span className="font-normal text-muted-foreground">
                                                    · {roleLabel(report.role)}
                                                </span>
                                            </p>
                                            <p className="truncate text-[13px] text-muted-foreground">
                                                {report.className} ·{' '}
                                                {report.date}, arrived{' '}
                                                {report.arrived}
                                            </p>
                                        </div>
                                    </div>
                                    <Badge tone="late">
                                        {report.minutesLate} min
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                    {repeat && (
                        <div className="mt-3 flex gap-3 rounded-md bg-secondary-95 px-4 py-3.5 text-sm text-secondary-10 dark:bg-secondary-20 dark:text-secondary-90">
                            <Icon name="flag" className="mt-0.5 size-4.5" />
                            <p>
                                {repeat.name} has been late {repeat.count} times
                                ({periodLabel}).{' '}
                                <Link
                                    href={latenessIndex({
                                        query: {
                                            status: 'all',
                                            search: repeat.name,
                                            from: filters.from,
                                            to: filters.to,
                                        },
                                    })}
                                    className="font-semibold underline-offset-4 hover:underline"
                                >
                                    Follow up
                                </Link>
                            </p>
                        </div>
                    )}
                </Card>
            </div>

            <div className="flex flex-wrap items-stretch gap-3 sm:gap-4">
                <Card className="flex-[1_1_340px] p-card">
                    <SectionHead
                        title={
                            trend.label === 'Last 6 weeks'
                                ? 'On-time arrivals, last 6 weeks'
                                : `On-time arrivals · ${filters.label}, ${trend.label.toLowerCase()}`
                        }
                        hint="Share of class arrivals that were on time"
                        action={
                            <div className="flex gap-3.5 text-[13px] text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <span className="size-2.5 rounded-[2px] bg-brand dark:bg-primary-70" />
                                    Students
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="size-2.5 rounded-[2px] bg-secondary-key" />
                                    Tutors
                                </span>
                            </div>
                        }
                    />
                    {trend.buckets.every(
                        (week) =>
                            week.students === null && week.tutors === null,
                    ) ? (
                        <p className="py-10 text-center text-sm text-muted-foreground">
                            This fills in once registers have been taken.
                        </p>
                    ) : (
                        <OnTimeChart weeks={trend.buckets} />
                    )}
                </Card>

                <Card className="flex-[1_1_340px] p-card">
                    <SectionHead
                        title="Latest end-of-class reports"
                        action={
                            <Link
                                href={lessonReportsIndex()}
                                className="text-sm font-semibold text-link hover:underline"
                            >
                                View all
                            </Link>
                        }
                    />
                    {latestReports.length === 0 ? (
                        <p className="py-4 text-sm text-muted-foreground">
                            No reports yet. Tutors and students can write one
                            after each class.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {latestReports.map((report) => (
                                <article
                                    key={report.id}
                                    className={cn(
                                        'flex flex-col gap-1.5 rounded-md p-3.5',
                                        report.role === 'tutor'
                                            ? 'bg-primary-95 dark:bg-primary-20/50'
                                            : 'bg-secondary-95 dark:bg-secondary-20/50',
                                    )}
                                >
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <p className="text-sm font-semibold">
                                            {report.author} ·{' '}
                                            {roleLabel(report.role)}
                                        </p>
                                        <span className="text-[13px] text-muted-foreground">
                                            {report.className} ·{' '}
                                            {report.submittedAt}
                                        </span>
                                    </div>
                                    <p className="text-sm leading-relaxed">
                                        {report.wentWell
                                            ? `“${report.wentWell}”`
                                            : 'No comments on what went well.'}
                                    </p>
                                    {report.recommendations && (
                                        <p
                                            className={cn(
                                                'text-[13px] font-semibold',
                                                report.role === 'tutor'
                                                    ? 'text-primary-30 dark:text-primary-80'
                                                    : 'text-secondary-30 dark:text-secondary-80',
                                            )}
                                        >
                                            Recommends: {report.recommendations}
                                        </p>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                </Card>
            </div>

            <Card className="p-card">
                <SectionHead
                    title="Quizzes this week"
                    hint={
                        quizzes.available
                            ? undefined
                            : 'Quiz activity appears here once the quiz platform launches.'
                    }
                />
                <ul className="flex flex-col gap-3">
                    {quizzes.categories.map((category, index) => (
                        <li
                            key={category.label}
                            className="grid grid-cols-[56px_1fr_40px] items-center gap-3 text-sm sm:grid-cols-[120px_1fr_48px]"
                        >
                            <span>{category.label}</span>
                            <span className="h-1.5 overflow-hidden rounded-full bg-surface-muted">
                                <span
                                    className={cn(
                                        'block h-full rounded-full',
                                        index < 2
                                            ? 'bg-primary'
                                            : 'bg-secondary-40',
                                    )}
                                    style={{
                                        width: `${(category.count / largestQuizCategory) * 100}%`,
                                    }}
                                />
                            </span>
                            <span className="text-right text-[13px] tabular-nums">
                                {category.count.toLocaleString('en-GB')}
                            </span>
                        </li>
                    ))}
                </ul>
                <hr className="my-4 border-0 border-t" />
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs">
                            Question bank
                        </p>
                        <p className="text-base font-bold sm:text-lg">
                            {quizzes.questionBank.toLocaleString('en-GB')}{' '}
                            {quizzes.questionBank === 1
                                ? 'question'
                                : 'questions'}
                        </p>
                    </div>
                    <Link
                        href={questionsIndex()}
                        className={buttonClasses('outline', 'sm')}
                    >
                        Manage
                    </Link>
                </div>
            </Card>
        </AppLayout>
    );
}
