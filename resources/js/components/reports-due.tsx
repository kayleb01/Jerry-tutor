import { Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { buttonClasses } from '@/components/ui/button';
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
        <Card className="border-secondary-90 bg-secondary-95 dark:border-secondary-20 dark:bg-secondary-20/40">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon
                        name="report"
                        className="text-secondary-40 dark:text-secondary-80"
                    />
                    Reports to write
                </CardTitle>
                <CardDescription>
                    Share how these lessons went while they’re fresh.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y divide-secondary-90 dark:divide-secondary-20">
                    {reports.map((report) => (
                        <li
                            key={report.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                        >
                            <div>
                                <p className="font-semibold">
                                    {report.className}
                                </p>
                                <p className="text-muted-foreground">
                                    {report.when} · open until {report.closesAt}
                                </p>
                            </div>
                            <Link
                                href={editReport(report.id)}
                                className={buttonClasses('primary')}
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
