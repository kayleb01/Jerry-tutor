import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import ReportsDue from '@/components/reports-due';
import type { ReportDue } from '@/components/reports-due';
import AppLayout from '@/layouts/app-layout';
import { checkIn } from '@/routes/lessons';
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
    upcoming: { id: number; className: string; when: string }[];
};

export default function TutorDashboard({
    today,
    upcoming,
    reportsDue,
}: TutorDashboardProps) {
    return (
        <AppLayout title="Today">
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
                <ul className="grid gap-4 md:grid-cols-2">
                    {today.map((lesson) => (
                        <li key={lesson.id}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>{lesson.className}</CardTitle>
                                    <CardDescription>
                                        {lesson.time}
                                        {lesson.location
                                            ? ` · ${lesson.location}`
                                            : ''}{' '}
                                        · {lesson.students} students
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap items-center gap-3">
                                    {lesson.checkedInAt ? (
                                        <Badge
                                            tone={
                                                lesson.late ? 'late' : 'present'
                                            }
                                        >
                                            Checked in at {lesson.checkedInAt}
                                        </Badge>
                                    ) : lesson.canCheckIn ? (
                                        <Link
                                            href={checkIn(lesson.id)}
                                            as="button"
                                            className="inline-flex h-12 items-center rounded-md bg-accent px-6 text-base font-medium text-accent-foreground shadow-card hover:bg-accent-hover"
                                        >
                                            Check in
                                        </Link>
                                    ) : (
                                        <Badge tone="neutral">
                                            Check-in opens 30 min before
                                        </Badge>
                                    )}
                                    <Link
                                        href={register(lesson.id)}
                                        className="inline-flex h-10 items-center rounded-md border border-input bg-surface px-4 text-sm font-medium hover:bg-surface-muted"
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
                                            className="inline-flex h-10 items-center justify-center rounded-md border border-input bg-surface px-4 text-sm font-medium text-primary hover:bg-surface-muted"
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
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
            {upcoming.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold">Coming up</h2>
                    <ul className="divide-y rounded-lg border bg-surface">
                        {upcoming.map((lesson) => (
                            <li
                                key={lesson.id}
                                className="flex justify-between px-4 py-3 text-sm"
                            >
                                <span className="font-medium">
                                    {lesson.className}
                                </span>
                                <span className="text-muted-foreground">
                                    {lesson.when}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AppLayout>
    );
}
