import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import FromAcademy from '@/components/from-academy';
import Icon from '@/components/icon';
import PersonAvatar from '@/components/person-avatar';
import { buttonClasses } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { checkIn } from '@/routes/lessons';
import { show as register } from '@/routes/lessons/register';
import { edit as editReport } from '@/routes/lessons/report';
import { acknowledge as acknowledgeIssue } from '@/routes/raised-issues';

type NextLesson = {
    id: number;
    className: string;
    time: string;
    location: string | null;
    students: number;
    startsAt: string;
    state: 'upcoming' | 'in_progress';
    isToday: boolean;
    canCheckIn: boolean;
    checkedInAt: string | null;
    minutesLate: number | null;
};

type WeekLesson = {
    id: number;
    day: string;
    time: string;
    className: string;
    state: 'past' | 'today' | 'upcoming' | 'cancelled';
    summary: string;
    report: 'submitted' | 'draft' | 'needed' | null;
    canOpenRegister: boolean;
};

type TutorDashboardProps = {
    header: { date: string; greeting: string; now: string };
    nextLesson: NextLesson | null;
    stats: {
        punctuality: {
            percent: number | null;
            onTime: number;
            attended: number;
        };
        week: { total: number; done: number; toGo: number };
        reports: { count: number; oldest: string | null };
        studentAttendance: number | null;
    };
    week: WeekLesson[];
    studentsToWatch: {
        id: number;
        name: string;
        yearGroup: string | null;
        reasons: string;
    }[];
    fromAcademy: {
        id: number;
        className: string;
        when: string;
        note: string;
        raisedBy: string | null;
    }[];
};

const smallCaps =
    'text-[0.6875rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

function useNow(initial: string): string {
    const [now, setNow] = useState(initial);
    useEffect(() => {
        setNow(initial);
    }, [initial]);
    useEffect(() => {
        const timer = setInterval(() => {
            const date = new Date();
            setNow(
                date.toLocaleTimeString('en-GB', {
                    hour: '2-digit',
                    minute: '2-digit',
                    timeZone: undefined,
                }),
            );
        }, 60000);
        return () => clearInterval(timer);
    }, []);
    return now;
}

function bannerKicker(lesson: NextLesson, nowMs: number): string {
    if (lesson.state === 'in_progress') {
        return 'Next class · In progress';
    }
    const start = new Date(lesson.startsAt);
    if (!lesson.isToday) {
        return `Next class · ${start.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short' })}`;
    }
    const minutes = Math.max(1, Math.ceil((start.getTime() - nowMs) / 60000));
    if (minutes >= 60) {
        return `Next class · Today, ${lesson.time.split(' ')[0]}`;
    }
    return `Next class · Starts in ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
}

export default function TutorDashboard({
    header,
    nextLesson,
    stats,
    week,
    studentsToWatch,
    fromAcademy,
}: TutorDashboardProps) {
    const now = useNow(header.now);
    const [nowMs, setNowMs] = useState(() => Date.now());
    useEffect(() => {
        setNowMs(Date.now());
    }, [now]);

    return (
        <AppLayout
            title={header.greeting}
            subtitle={`${header.date} · Now ${now}`}
        >
            <FromAcademy
                notes={fromAcademy.map((note) => ({
                    id: note.id,
                    heading: `${note.className} · ${note.when}`,
                    note: note.note,
                    from: note.raisedBy,
                }))}
                description="Notes about your classes from the academy office."
                acknowledge={(id) => acknowledgeIssue.form(id)}
            />

            {nextLesson ? (
                <Banner lesson={nextLesson} nowMs={nowMs} />
            ) : (
                <Card className="p-card">
                    <p className="text-body text-muted-foreground">
                        No classes scheduled.
                    </p>
                </Card>
            )}

            <section
                className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4"
                aria-label="Totals"
            >
                <Total
                    label="My punctuality · last 90 days"
                    value={
                        stats.punctuality.percent === null
                            ? '—'
                            : `${stats.punctuality.percent}%`
                    }
                    hint={
                        stats.punctuality.attended === 0
                            ? 'No classes attended yet'
                            : `${stats.punctuality.onTime} of ${stats.punctuality.attended} on time`
                    }
                />
                <Total
                    label="Classes this week"
                    value={String(stats.week.total)}
                    hint={`${stats.week.done} done · ${stats.week.toGo} to go`}
                />
                <Total
                    label="Reports to submit"
                    value={String(stats.reports.count)}
                    valueClassName="text-late"
                    hint={stats.reports.oldest ?? 'All caught up'}
                />
                <Total
                    label="Student attendance · last 90 days"
                    value={
                        stats.studentAttendance === null
                            ? '—'
                            : `${stats.studentAttendance}%`
                    }
                    hint="Across your classes"
                />
            </section>

            <div className="grid items-start gap-3 sm:gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>This week&rsquo;s classes</CardTitle>
                    </CardHeader>
                    <CardContent className="pt-3">
                        {week.length === 0 ? (
                            <p className="py-3 text-sm text-muted-foreground">
                                No classes this week.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {week.map((lesson) => (
                                    <WeekRow key={lesson.id} lesson={lesson} />
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Students to watch</CardTitle>
                        <CardDescription>
                            From attendance, lateness and quiz results in your
                            classes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="pt-3">
                        {studentsToWatch.length === 0 ? (
                            <p className="py-3 text-sm text-muted-foreground">
                                Nobody to flag right now.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {studentsToWatch.map((student) => (
                                    <li
                                        key={student.id}
                                        className="flex items-start gap-3 py-3.5"
                                    >
                                        <PersonAvatar
                                            name={student.name}
                                            role="student"
                                        />
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold">
                                                {student.name}
                                                {student.yearGroup
                                                    ? ` · ${student.yearGroup}`
                                                    : ''}
                                            </p>
                                            <p className="text-[0.8125rem] text-muted-foreground">
                                                {student.reasons}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function Banner({ lesson, nowMs }: { lesson: NextLesson; nowMs: number }) {
    return (
        <section className="relative flex flex-col gap-4 overflow-hidden rounded-lg bg-brand p-card text-brand-foreground sm:gap-5 sm:p-6">
            <span
                aria-hidden="true"
                className="absolute -top-8 -right-8 size-20 rounded-bl-full bg-secondary-key sm:size-28"
            />
            <div className="relative flex flex-col gap-1.5">
                <p className="text-xs font-semibold tracking-[0.08em] text-secondary-80 uppercase">
                    {bannerKicker(lesson, nowMs)}
                </p>
                <h2 className="text-section">{lesson.className}</h2>
                <p className="text-body text-primary-90">
                    {lesson.time}
                    {lesson.location ? ` · ${lesson.location}` : ''} ·{' '}
                    {lesson.students} students
                </p>
            </div>
            <div className="relative flex flex-wrap items-center gap-x-4 gap-y-2">
                {lesson.checkedInAt ? (
                    <>
                        <Link
                            href={register(lesson.id)}
                            className={buttonClasses('accent', 'lg')}
                        >
                            Open register
                        </Link>
                        <p className="text-sm text-primary-80">
                            Checked in {lesson.checkedInAt} ·{' '}
                            {lesson.minutesLate
                                ? `${lesson.minutesLate} min late`
                                : 'on time'}
                        </p>
                    </>
                ) : lesson.canCheckIn ? (
                    <>
                        <Link
                            href={checkIn(lesson.id)}
                            as="button"
                            className={buttonClasses('accent', 'lg')}
                        >
                            Check in to start the class
                        </Link>
                        <p className="text-sm text-primary-80">
                            Your arrival time is recorded for punctuality.
                        </p>
                    </>
                ) : null}
            </div>
        </section>
    );
}

function Total({
    label,
    value,
    hint,
    valueClassName,
}: {
    label: string;
    value: string;
    hint: string;
    valueClassName?: string;
}) {
    return (
        <Card className="flex flex-col gap-1 p-card sm:gap-1.5">
            <p className={smallCaps}>{label}</p>
            <p className={cn('text-stat tabular-nums', valueClassName)}>
                {value}
            </p>
            <p className="text-xs text-muted-foreground sm:text-[0.8125rem]">
                {hint}
            </p>
        </Card>
    );
}

function WeekRow({ lesson }: { lesson: WeekLesson }) {
    const isToday = lesson.state === 'today';

    return (
        <li
            className={cn(
                'flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3.5',
                isToday && '-mx-3 rounded-md bg-surface-muted px-3',
            )}
        >
            <div className="min-w-0">
                <p className="text-sm font-semibold">
                    <span className="text-muted-foreground">
                        {lesson.day} {lesson.time}
                    </span>{' '}
                    · {lesson.className}
                </p>
                <p className="text-[0.8125rem] text-muted-foreground">
                    {lesson.summary}
                </p>
            </div>
            <WeekAction lesson={lesson} />
        </li>
    );
}

function WeekAction({ lesson }: { lesson: WeekLesson }) {
    const sr = <span className="sr-only"> for {lesson.className}</span>;

    if (lesson.state === 'today') {
        return lesson.canOpenRegister ? (
            <Link
                href={register(lesson.id)}
                className={buttonClasses('outline', 'sm')}
            >
                Open register
                {sr}
            </Link>
        ) : null;
    }
    if (lesson.state === 'upcoming') {
        return <span className="text-sm text-muted-foreground">Upcoming</span>;
    }
    if (lesson.state === 'cancelled') {
        return <span className="text-sm text-muted-foreground">Cancelled</span>;
    }
    if (lesson.report === 'submitted') {
        return (
            <Link
                href={editReport(lesson.id)}
                className="inline-flex items-center gap-1.5 text-sm font-semibold text-present hover:underline"
            >
                <Icon name="check" className="size-4" />
                Report submitted
                {sr}
            </Link>
        );
    }
    if (lesson.report === 'draft') {
        return (
            <Link
                href={editReport(lesson.id)}
                className={buttonClasses('outline', 'sm')}
            >
                Finish report
                {sr}
            </Link>
        );
    }
    if (lesson.report === 'needed') {
        return (
            <Link
                href={editReport(lesson.id)}
                className={buttonClasses('primary', 'sm')}
            >
                Write report
                {sr}
            </Link>
        );
    }
    return null;
}
