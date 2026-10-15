import { Form } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import PersonAvatar from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { raise, review } from '@/routes/admin/lesson-reports';

export type ClassReport = {
    id: number;
    author: string;
    rating: number | null;
    wentWell: string | null;
    issues: string | null;
    recommendations: string | null;
    submittedAt: string;
    needsAttention: boolean;
};

export type ClassReportLesson = {
    id: number;
    className: string;
    tutor: string | null;
    when: string;
    attendance: string | null;
    state: string;
    stateLabel: string;
    latestSubmittedAt: string | null;
    reportWindowClosesAt: string | null;
    tutorReport: ClassReport | null;
    studentReports: ClassReport[];
    studentSummary: {
        count: number;
        averageRating: number | null;
        needsAttention: number;
    };
    raisedIssues: {
        id: number;
        note: string;
        raisedBy: string | null;
        raisedAt: string;
        acknowledgedAt: string | null;
    }[];
    reviewed: { at: string; by: string | null } | null;
};

const STUDENTS_SHOWN = 3;

const smallCaps =
    'text-[0.6875rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

type ClassReportDetailProps = {
    lesson: ClassReportLesson;
    /** The lesson to open after "Mark as reviewed", if any. */
    nextLessonId: number | null;
};

/** One class's tutor and student reports, with the review actions. */
export default function ClassReportDetail({
    lesson,
    nextLessonId,
}: ClassReportDetailProps) {
    const [showAllStudents, setShowAllStudents] = useState(false);
    const [raising, setRaising] = useState(false);
    const students = showAllStudents
        ? lesson.studentReports
        : lesson.studentReports.slice(0, STUDENTS_SHOWN);
    const hiddenStudents = lesson.studentReports.length - students.length;
    const summary = lesson.studentSummary;

    return (
        <div className="flex min-w-0 flex-col gap-3 sm:gap-4">
            <Card className="flex flex-wrap items-start justify-between gap-3 p-card">
                <div className="min-w-0">
                    <h2 className="text-section font-bold">
                        {lesson.className}
                    </h2>
                    <p className="text-[0.8125rem] text-muted-foreground sm:text-sm">
                        {[lesson.when, lesson.tutor, lesson.attendance]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {lesson.reviewed && (
                        <p className="mt-1 flex items-center gap-1.5 text-[0.8125rem] text-muted-foreground">
                            <Icon name="check" className="size-4" />
                            Reviewed by {lesson.reviewed.by ?? 'an admin'},{' '}
                            {lesson.reviewed.at}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    {lesson.tutorReport && (
                        <span className="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary-95 px-2.5 text-xs font-semibold text-primary-30 dark:bg-primary-20 dark:text-primary-90">
                            <Icon name="users" className="size-3.5" />
                            Tutor report
                        </span>
                    )}
                    {summary.count > 0 && (
                        <span className="inline-flex h-7 items-center gap-1.5 rounded-md bg-primary-95 px-2.5 text-xs font-semibold text-primary-30 dark:bg-primary-20 dark:text-primary-90">
                            <Icon name="users" className="size-3.5" />
                            {summary.count}{' '}
                            {summary.count === 1
                                ? 'student report'
                                : 'student reports'}
                        </span>
                    )}
                </div>
            </Card>

            {lesson.tutorReport ? (
                <TutorReportCard
                    report={lesson.tutorReport}
                    tutor={lesson.tutor}
                />
            ) : (
                <Card className="flex items-center gap-3 p-card text-sm text-muted-foreground">
                    <Icon name="clock" className="size-5 shrink-0" />
                    <p>
                        No tutor report yet.
                        {lesson.state === 'pending' &&
                            lesson.reportWindowClosesAt &&
                            ` The window closes ${lesson.reportWindowClosesAt}.`}
                        {lesson.state === 'missing' &&
                            ' The reporting window has closed.'}
                    </p>
                </Card>
            )}

            <Card className="p-card">
                <div className="flex flex-wrap items-baseline justify-between gap-2 border-b pb-3">
                    <h3 className="text-base font-semibold sm:text-lg">
                        Student reports
                    </h3>
                    <span className="text-[0.8125rem] text-muted-foreground">
                        Shown to admin only
                    </span>
                </div>
                {summary.count === 0 ? (
                    <p className="pt-4 text-sm text-muted-foreground">
                        No student reports yet.
                    </p>
                ) : (
                    <>
                        <p className="pt-3 text-[0.8125rem] text-muted-foreground sm:text-sm">
                            {summary.count}{' '}
                            {summary.count === 1 ? 'report' : 'reports'}
                            {summary.averageRating !== null &&
                                ` · average ${summary.averageRating} / 5`}
                            {summary.needsAttention > 0 && (
                                <span className="font-semibold text-danger">
                                    {' '}
                                    · {summary.needsAttention}{' '}
                                    {summary.needsAttention === 1
                                        ? 'needs'
                                        : 'need'}{' '}
                                    attention
                                </span>
                            )}
                        </p>
                        <ul className="divide-y">
                            {students.map((report) => (
                                <StudentReportRow
                                    key={report.id}
                                    report={report}
                                />
                            ))}
                        </ul>
                        {lesson.studentReports.length > STUDENTS_SHOWN && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="mt-1"
                                onClick={() =>
                                    setShowAllStudents((shown) => !shown)
                                }
                                aria-expanded={showAllStudents}
                            >
                                {showAllStudents
                                    ? 'Show fewer'
                                    : `Show all ${lesson.studentReports.length} (${hiddenStudents} more)`}
                            </Button>
                        )}
                    </>
                )}
            </Card>

            {lesson.raisedIssues.length > 0 && (
                <Card className="flex flex-col gap-3 p-card">
                    <h3 className="text-base font-semibold sm:text-lg">
                        Raised with {lesson.tutor ?? 'the tutor'}
                    </h3>
                    <ul className="flex flex-col gap-3">
                        {lesson.raisedIssues.map((issue) => (
                            <li
                                key={issue.id}
                                className="rounded-md border-l-4 border-danger/60 bg-surface-muted px-3.5 py-3 text-sm"
                            >
                                <p>{issue.note}</p>
                                <p className="mt-1 text-[0.8125rem] text-muted-foreground">
                                    {issue.raisedBy ?? 'An admin'},{' '}
                                    {issue.raisedAt} ·{' '}
                                    {issue.acknowledgedAt
                                        ? `Read by the tutor ${issue.acknowledgedAt}`
                                        : 'Waiting for the tutor'}
                                </p>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {raising && (
                <Card className="p-card">
                    <Form
                        {...raise.form(lesson.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setRaising(false)}
                        className="flex flex-col gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                {lesson.latestSubmittedAt && (
                                    <input
                                        type="hidden"
                                        name="seen"
                                        value={lesson.latestSubmittedAt}
                                    />
                                )}
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="note">
                                        Note to {lesson.tutor ?? 'the tutor'}
                                    </Label>
                                    <Textarea
                                        id="note"
                                        name="note"
                                        rows={3}
                                        maxLength={1000}
                                        required
                                        autoFocus
                                        placeholder="e.g. Please recap place value for students who arrived late."
                                        aria-invalid={!!errors.note}
                                        aria-describedby="note-hint note-error"
                                    />
                                    <p
                                        id="note-hint"
                                        className="text-[0.8125rem] text-muted-foreground"
                                    >
                                        Shown on their dashboard until they mark
                                        it as read.
                                    </p>
                                    <FieldError
                                        id="note-error"
                                        message={errors.note}
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Send to tutor
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        onClick={() => setRaising(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Card>
            )}

            <div className="flex flex-wrap justify-end gap-2 sm:gap-3">
                {!raising && (
                    <Button variant="outline" onClick={() => setRaising(true)}>
                        Raise with tutor
                    </Button>
                )}
                {!lesson.reviewed && (
                    <Form
                        {...review.form(lesson.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <>
                                {lesson.latestSubmittedAt && (
                                    <input
                                        type="hidden"
                                        name="seen"
                                        value={lesson.latestSubmittedAt}
                                    />
                                )}
                                {nextLessonId !== null && (
                                    <input
                                        type="hidden"
                                        name="next"
                                        value={nextLessonId}
                                    />
                                )}
                                <Button type="submit" disabled={processing}>
                                    Mark as reviewed
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </div>
    );
}

function TutorReportCard({
    report,
    tutor,
}: {
    report: ClassReport;
    tutor: string | null;
}) {
    return (
        <Card className="flex flex-col gap-4 border-primary-90 p-card dark:border-primary-20">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <PersonAvatar
                        name={tutor ?? report.author}
                        role="tutor"
                        className="size-10"
                    />
                    <div>
                        <p className="font-semibold">
                            Tutor report · {report.author}
                        </p>
                        <p className="text-[0.8125rem] text-muted-foreground">
                            Submitted {report.submittedAt}
                        </p>
                    </div>
                </div>
                {report.rating !== null && (
                    <p className="text-sm font-semibold">
                        Lesson rating {report.rating} / 5
                    </p>
                )}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <p className={smallCaps}>What went well</p>
                    <p className="mt-1 text-sm leading-relaxed">
                        {report.wentWell ?? '—'}
                    </p>
                </div>
                <div>
                    <p className={smallCaps}>To improve</p>
                    <p className="mt-1 text-sm leading-relaxed">
                        {report.issues ?? '—'}
                    </p>
                </div>
            </div>
            {report.recommendations && (
                <div className="flex gap-3 rounded-md bg-primary-95 px-4 py-3.5 text-sm dark:bg-primary-20/50">
                    <Icon
                        name="bulb"
                        className="mt-0.5 size-4 shrink-0 text-primary-30 dark:text-primary-80"
                    />
                    <p>
                        <span className="font-semibold">Recommendation:</span>{' '}
                        {report.recommendations}
                    </p>
                </div>
            )}
        </Card>
    );
}

function StudentReportRow({ report }: { report: ClassReport }) {
    const said = [report.wentWell, report.issues].filter(Boolean);

    return (
        <li className="flex gap-3 py-4">
            <PersonAvatar name={report.author} role="student" />
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <p className="flex flex-wrap items-center gap-2 font-semibold">
                        {report.author}
                        {report.needsAttention && (
                            <span className="inline-flex h-5 items-center gap-1 rounded-md bg-absent-soft px-1.5 text-[0.6875rem] font-semibold text-absent">
                                <Icon name="flag" className="size-3" />
                                Needs attention
                            </span>
                        )}
                    </p>
                    {report.rating !== null && (
                        <span
                            className={cn(
                                'text-[0.8125rem]',
                                report.rating <= 2
                                    ? 'font-semibold text-danger'
                                    : 'text-muted-foreground',
                            )}
                        >
                            Rated {report.rating} / 5
                        </span>
                    )}
                </div>
                {said.map((text) => (
                    <p
                        key={text}
                        className="mt-1 text-sm leading-relaxed break-words"
                    >
                        “{text}”
                    </p>
                ))}
                {report.recommendations && (
                    <p className="mt-1 text-[0.8125rem] font-semibold text-secondary-30 dark:text-secondary-80">
                        Recommends: {report.recommendations}
                    </p>
                )}
            </div>
        </li>
    );
}
