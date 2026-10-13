import { Link } from '@inertiajs/react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
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
        <article className="flex min-w-60 flex-col gap-3 rounded-lg border bg-surface-muted/60 p-4">
            <div className="flex items-start justify-between gap-2">
                <h3 className="leading-tight font-semibold">
                    {lesson.className}
                </h3>
                <Badge tone={state.tone}>{state.label}</Badge>
            </div>
            <div className="flex items-center justify-between rounded-md bg-surface p-3 shadow-card">
                <div>
                    <p className="font-display text-xl font-bold tabular-nums">
                        {lesson.time}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {lesson.location ?? 'Location to be confirmed'}
                    </p>
                </div>
                <Icon name="book" className="size-7 text-royal-400" />
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
                    className="inline-flex h-10 items-center justify-center rounded-md border border-input bg-surface px-4 text-sm font-medium text-primary hover:bg-surface-muted"
                >
                    {lesson.reported ? 'Edit your report' : 'Write a report'}
                    <span className="sr-only"> for {lesson.className}</span>
                </Link>
            )}
        </article>
    );
}
