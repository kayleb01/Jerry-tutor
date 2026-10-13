import { Link } from '@inertiajs/react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import StatCard from '@/components/stat-card';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as childrenIndex } from '@/routes/children';

type ChildAttendanceProps = {
    child: { id: number; name: string; yearGroup: string | null };
    stats: {
        attendance: number | null;
        onTime: number | null;
        late: number;
        absent: number;
    };
    records: {
        id: number;
        date: string;
        className: string;
        time: string;
        status: AttendanceStatus;
        minutesLate: number | null;
    }[];
};

const percent = (value: number | null) => (value === null ? '—' : `${value}%`);

export default function ChildAttendance({
    child,
    stats,
    records,
}: ChildAttendanceProps) {
    return (
        <AppLayout
            title={`${child.name}'s attendance`}
            subtitle={[child.yearGroup, 'Last 30 days']
                .filter(Boolean)
                .join(' · ')}
        >
            <div>
                <Link
                    href={childrenIndex()}
                    className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                >
                    ← Back to your children
                </Link>
            </div>
            <section
                className="grid gap-4 md:grid-cols-3"
                aria-label="Attendance summary"
            >
                <StatCard
                    value={percent(stats.attendance)}
                    label="Attendance"
                    hint={
                        stats.attendance === null
                            ? 'Shows once classes are marked'
                            : 'Classes attended, excluding excused'
                    }
                    icon="chart"
                    tone="royal"
                />
                <StatCard
                    value={percent(stats.onTime)}
                    label="On time"
                    hint={
                        stats.onTime === null
                            ? 'Shows once a class is attended'
                            : 'Of the classes attended'
                    }
                    icon="check"
                    tone="present"
                />
                <StatCard
                    value={String(stats.late)}
                    label="Late arrivals"
                    hint={`${stats.absent} absent`}
                    icon="clock"
                    tone="gold"
                />
            </section>
            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <caption className="sr-only">
                            Recent classes for {child.name}
                        </caption>
                        <thead className="border-b bg-surface-muted text-muted-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Date
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Class
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Time
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Attendance
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No classes recorded yet.
                                    </td>
                                </tr>
                            )}
                            {records.map((record) => (
                                <tr
                                    key={record.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {record.date}
                                    </td>
                                    <td className="px-4 py-3">
                                        {record.className}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap tabular-nums">
                                        {record.time}
                                    </td>
                                    <td className="px-4 py-3">
                                        <AttendanceStatusBadge
                                            status={record.status}
                                            minutesLate={record.minutesLate}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
