import { Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { buttonClasses } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import FromAcademy from '@/components/from-academy';
import ReportsDue from '@/components/reports-due';
import type { ReportDue } from '@/components/reports-due';
import AppLayout from '@/layouts/app-layout';
import { checkIn } from '@/routes/lessons';
import { acknowledge as acknowledgeIssue } from '@/routes/raised-issues';
import { show as register } from '@/routes/lessons/register';
import { edit as editReport } from '@/routes/lessons/report';

type TodayLesson = {
    id: number;
    className: string;
    time: string;
    location: string | null;
    status: string;
    checkedInAt: string | null;
    late: boolean;
    students: number;
    canCheckIn: boolean;
    canReport: boolean;
    reported: boolean;
};

type TutorDashboardProps = {
    today: TodayLesson[];
    reportsDue: ReportDue[];
    fromAcademy: {
        id: number;
        className: string;
        when: string;
        note: string;
        raisedBy: string | null;
    }[];
    upcoming: { id: number; className: string; when: string }[];
};

export default function TutorDashboard({
    today,
    upcoming,
    reportsDue,
    fromAcademy,
}: TutorDashboardProps) {
    return (
        <AppLayout title="Today" subtitle="Your classes">
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
            <ReportsDue reports={reportsDue} />
            {today.length === 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle>No lessons today</CardTitle>
                        <CardDescription>
                            Your next lessons are listed below.
                        </CardDescription>
                    </CardHeader>
                    <CardContent />
                </Card>
            ) : (
                <ul className="grid gap-3 sm:gap-4 lg:grid-cols-2">
                    {today.map((lesson) => (
                        <li key={lesson.id}>
                            <article className="relative flex h-full flex-col gap-4 overflow-hidden rounded-lg bg-brand p-card text-brand-foreground sm:gap-5 sm:p-6">
                                <span
                                    aria-hidden="true"
                                    className="absolute -top-8 -right-8 size-20 rounded-bl-full bg-secondary-key sm:size-28"
                                />
                                <div className="relative flex flex-col gap-1.5">
                                    <p className="text-xs font-semibold tracking-[0.08em] text-secondary-80 uppercase">
                                        {lesson.time}
                                    </p>
                                    <h2 className="text-section">
                                        {lesson.className}
                                    </h2>
                                    <p className="text-body text-primary-90">
                                        {lesson.location
                                            ? `${lesson.location} · `
                                            : ''}
                                        {lesson.students} students
                                    </p>
                                </div>
                                <div className="relative mt-auto flex flex-wrap items-center gap-3">
                                    {lesson.checkedInAt ? (
                                        <Badge
                                            tone={
                                                lesson.late ? 'late' : 'present'
                                            }
                                            className="h-8 px-3 text-sm"
                                        >
                                            <Icon
                                                name={
                                                    lesson.late
                                                        ? 'alarm'
                                                        : 'clock'
                                                }
                                                className="size-4"
                                            />
                                            Checked in at {lesson.checkedInAt}
                                        </Badge>
                                    ) : lesson.canCheckIn ? (
                                        <Link
                                            href={checkIn(lesson.id)}
                                            as="button"
                                            className={buttonClasses(
                                                'accent',
                                                'lg',
                                            )}
                                        >
                                            Check in to start the class
                                        </Link>
                                    ) : (
                                        <span className="text-sm text-primary-80">
                                            Check-in opens 30 min before
                                        </span>
                                    )}
                                    <Link
                                        href={register(lesson.id)}
                                        className={buttonClasses(
                                            'outline',
                                            'md',
                                            'border-primary-70 text-white hover:bg-primary-20 hover:text-white',
                                        )}
                                    >
                                        Take register
                                        <span className="sr-only">
                                            {' '}
                                            for {lesson.className}
                                        </span>
                                    </Link>
                                    {lesson.canReport && (
                                        <Link
                                            href={editReport(lesson.id)}
                                            className={buttonClasses(
                                                'outline',
                                                'md',
                                                'border-primary-70 text-white hover:bg-primary-20 hover:text-white',
                                            )}
                                        >
                                            {lesson.reported
                                                ? 'Edit your report'
                                                : 'Write a report'}
                                            <span className="sr-only">
                                                {' '}
                                                for {lesson.className}
                                            </span>
                                        </Link>
                                    )}
                                </div>
                                <p className="relative text-[0.8125rem] text-primary-80">
                                    Your check-in time is recorded for
                                    punctuality.
                                </p>
                            </article>
                        </li>
                    ))}
                </ul>
            )}
            {upcoming.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>Coming up</CardTitle>
                    </CardHeader>
                    <CardContent className="pt-3">
                        <ul className="divide-y">
                            {upcoming.map((lesson) => (
                                <li
                                    key={lesson.id}
                                    className="flex flex-wrap justify-between gap-2 py-3.5 text-sm"
                                >
                                    <span className="font-semibold">
                                        {lesson.className}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {lesson.when}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}
