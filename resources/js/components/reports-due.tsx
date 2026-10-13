import { Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { edit as editReport } from '@/routes/lessons/report';

export type ReportDue = {
    id: number;
    className: string;
    when: string;
    closesAt: string;
};

export default function ReportsDue({ reports }: { reports: ReportDue[] }) {
    if (reports.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon name="book" className="text-gold-600" />
                    Reports to write
                </CardTitle>
                <CardDescription>
                    Share how these lessons went while they’re fresh.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y">
                    {reports.map((report) => (
                        <li
                            key={report.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                        >
                            <div>
                                <p className="font-medium">
                                    {report.className}
                                </p>
                                <p className="text-muted-foreground">
                                    {report.when} · open until {report.closesAt}
                                </p>
                            </div>
                            <Link
                                href={editReport(report.id)}
                                className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                            >
                                Write report
                                <span className="sr-only">
                                    {' '}
                                    for {report.className}, {report.when}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
