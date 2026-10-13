import { Link } from '@inertiajs/react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { buttonClasses } from '@/components/ui/button';
import type { BadgeTone } from '@/components/ui/badge';
import { edit as editReport } from '@/routes/lessons/report';
import type { DashboardLesson, LessonState } from '@/types';

const states: Record<LessonState, { label: string; tone: BadgeTone }> = {
    upcoming: { label: 'Not started', tone: 'neutral' },
    in_progress: { label: 'In progress', tone: 'gold' },
    finished: { label: 'Finished', tone: 'brand' },
    cancelled: { label: 'Cancelled', tone: 'absent' },
};

export default function LessonActivityCard({
    lesson,
}: {
    lesson: DashboardLesson;
}) {
    const state = states[lesson.state];

    return (
        <article className="flex min-w-60 flex-col gap-3 rounded-lg border bg-surface p-4">
            <div className="flex items-start justify-between gap-2">
                <h3 className="leading-snug font-bold">{lesson.className}</h3>
                <Badge tone={state.tone}>{state.label}</Badge>
            </div>
            <div className="flex items-center justify-between rounded-md bg-primary-95 p-3 dark:bg-primary-20">
                <div>
                    <p className="text-xl font-bold tabular-nums">
                        {lesson.time}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {lesson.location ?? 'Location to be confirmed'}
                    </p>
                </div>
                <Icon
                    name="book"
                    className="size-7 text-primary-50 dark:text-primary-80"
                />
            </div>
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Icon name="clock" className="size-4" /> Time: {lesson.minutes}{' '}
                minutes
            </p>
            <div className="flex min-h-6 items-center">
                {lesson.attendance ? (
                    <AttendanceStatusBadge status={lesson.attendance} />
                ) : (
                    <span className="text-xs text-muted-foreground">
                        {lesson.state === 'cancelled'
                            ? 'No class today'
                            : 'Attendance not recorded yet'}
                    </span>
                )}
            </div>
            {lesson.canReport && (
                <Link
                    href={editReport(lesson.id)}
                    className={buttonClasses('outline')}
                >
                    {lesson.reported ? 'Edit your report' : 'Write a report'}
                    <span className="sr-only"> for {lesson.className}</span>
                </Link>
            )}
        </article>
    );
}
