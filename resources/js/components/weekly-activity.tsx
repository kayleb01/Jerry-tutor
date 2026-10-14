import { Link } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/icon';
import LessonActivityCard from '@/components/lesson-activity-card';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { DashboardDay } from '@/types';

const segment =
    'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-semibold';
const active =
    'bg-surface text-foreground shadow-[0_1px_3px_rgb(27_27_34/0.15)]';
const idle = 'text-muted-foreground hover:text-foreground';

type WeeklyActivityProps = {
    week: DashboardDay[];
    nextWeek: boolean;
};

export default function WeeklyActivity({
    week,
    nextWeek,
}: WeeklyActivityProps) {
    const [view, setView] = useState<'kanban' | 'list'>('kanban');

    return (
        <section
            className="flex flex-col gap-4"
            aria-labelledby="weekly-activity"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="weekly-activity" className="text-section">
                    Weekly activity
                </h2>
                <div className="flex flex-wrap items-center gap-3">
                    <div className="inline-flex rounded-md bg-surface-muted p-1">
                        <Link
                            href={dashboard()}
                            preserveScroll
                            aria-current={!nextWeek ? 'page' : undefined}
                            className={cn(segment, !nextWeek ? active : idle)}
                        >
                            This week
                        </Link>
                        <Link
                            href={dashboard({ query: { week: 'next' } })}
                            preserveScroll
                            aria-current={nextWeek ? 'page' : undefined}
                            className={cn(segment, nextWeek ? active : idle)}
                        >
                            Next week
                        </Link>
                    </div>
                    <div
                        className="inline-flex rounded-md bg-surface-muted p-1"
                        role="group"
                        aria-label="View"
                    >
                        <button
                            type="button"
                            aria-pressed={view === 'kanban'}
                            onClick={() => setView('kanban')}
                            className={cn(
                                segment,
                                view === 'kanban' ? active : idle,
                            )}
                        >
                            <Icon name="columns" className="size-4" /> Kanban
                        </button>
                        <button
                            type="button"
                            aria-pressed={view === 'list'}
                            onClick={() => setView('list')}
                            className={cn(
                                segment,
                                view === 'list' ? active : idle,
                            )}
                        >
                            <Icon name="list" className="size-4" /> List
                        </button>
                    </div>
                </div>
            </div>
            {week.length === 0 ? (
                <Card className="p-card text-center text-sm text-muted-foreground sm:p-8">
                    No lessons {nextWeek ? 'next' : 'this'} week.
                </Card>
            ) : view === 'kanban' ? (
                <div className="flex gap-4 overflow-x-auto pb-2">
                    {week.map((day) => (
                        <Card
                            key={day.key}
                            className="flex w-72 shrink-0 flex-col gap-3 p-4"
                        >
                            <div>
                                <h3 className="text-lg font-bold">
                                    {day.name}{' '}
                                    <span className="text-sm font-normal text-muted-foreground">
                                        {day.date}
                                    </span>
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Total time: {day.minutes} min
                                </p>
                            </div>
                            {day.lessons.map((lesson) => (
                                <LessonActivityCard
                                    key={lesson.id}
                                    lesson={lesson}
                                />
                            ))}
                        </Card>
                    ))}
                </div>
            ) : (
                <Card className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Day
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Class
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Time
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Minutes
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {week.flatMap((day) =>
                                day.lessons.map((lesson) => (
                                    <tr
                                        key={lesson.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3">
                                            {day.name} {day.date}
                                        </td>
                                        <td className="px-4 py-3 font-semibold">
                                            {lesson.className}
                                            {lesson.state === 'cancelled' && (
                                                <span className="ml-2 text-xs text-absent">
                                                    Cancelled
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {lesson.time}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {lesson.minutes}
                                        </td>
                                    </tr>
                                )),
                            )}
                        </tbody>
                    </table>
                </Card>
            )}
        </section>
    );
}
