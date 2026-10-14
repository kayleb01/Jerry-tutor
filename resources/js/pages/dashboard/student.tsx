import Icon from '@/components/icon';
import LessonActivityCard from '@/components/lesson-activity-card';
import StatCard from '@/components/stat-card';
import StrandCard from '@/components/strand-card';
import { Card } from '@/components/ui/card';
import WeeklyActivity from '@/components/weekly-activity';
import ReportsDue from '@/components/reports-due';
import type { ReportDue } from '@/components/reports-due';
import AppLayout from '@/layouts/app-layout';
import type {
    DashboardDay,
    DashboardLesson,
    Strand,
    StudentStats,
} from '@/types';

type StudentDashboardProps = {
    header: { yearGroup: string | null; weekLabel: string };
    stats: StudentStats;
    strands: Strand[];
    today: DashboardLesson[];
    todayMinutes: number;
    week: DashboardDay[];
    nextWeek: boolean;
    reportsDue: ReportDue[];
};

const percent = (value: number | null) => (value === null ? '—' : `${value}%`);

export default function StudentDashboard({
    header,
    stats,
    strands,
    today,
    todayMinutes,
    week,
    nextWeek,
    reportsDue,
}: StudentDashboardProps) {
    return (
        <AppLayout
            title="Dashboard"
            badge={header.yearGroup ?? undefined}
            subtitle={header.weekLabel}
        >
            <section
                className="grid gap-3 sm:grid-cols-3 sm:gap-4"
                aria-label="This week at a glance"
            >
                <StatCard
                    value={percent(stats.attendance)}
                    label="Attendance"
                    hint={
                        stats.attendance === null
                            ? 'Shows once your classes are marked'
                            : 'Last 30 days'
                    }
                    icon="chart"
                    tone="royal"
                />
                <StatCard
                    value={percent(stats.onTime)}
                    label="On time"
                    hint={
                        stats.onTime === null
                            ? 'Shows once you’ve attended a class'
                            : 'Of the classes you attended'
                    }
                    icon="check"
                    tone="present"
                />
                <StatCard
                    value={stats.classTime}
                    label="Class time this week"
                    hint={`${stats.lessonsThisWeek} ${stats.lessonsThisWeek === 1 ? 'lesson' : 'lessons'}`}
                    icon="clock"
                    tone="gold"
                />
            </section>

            <ReportsDue reports={reportsDue} />

            <section
                className="grid gap-3 sm:gap-4 md:grid-cols-2"
                aria-label="Maths topics"
            >
                {strands.map((strand) => (
                    <StrandCard
                        key={strand.key}
                        strand={strand}
                        yearGroup={header.yearGroup}
                    />
                ))}
            </section>

            <section
                className="flex flex-col gap-4"
                aria-labelledby="today-activity"
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="today-activity" className="text-section">
                        Today’s activity
                    </h2>
                    <span className="inline-flex h-9 items-center gap-2 rounded-full bg-surface-muted px-4 text-sm font-medium text-muted-foreground">
                        <Icon name="clock" className="size-4" /> Total time:{' '}
                        {todayMinutes} min
                    </span>
                </div>
                {today.length === 0 ? (
                    <Card className="p-card text-center text-sm text-muted-foreground sm:p-8">
                        No lessons today. Enjoy your day!
                    </Card>
                ) : (
                    <Card className="flex gap-4 overflow-x-auto p-4">
                        {today.map((lesson) => (
                            <LessonActivityCard
                                key={lesson.id}
                                lesson={lesson}
                            />
                        ))}
                    </Card>
                )}
            </section>

            <WeeklyActivity week={week} nextWeek={nextWeek} />
        </AppLayout>
    );
}
