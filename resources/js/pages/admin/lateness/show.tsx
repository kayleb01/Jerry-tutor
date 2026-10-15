import { Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import LatenessReportDetail from '@/components/lateness-report-detail';
import type { LatenessDetail } from '@/components/lateness-report-detail';
import { buttonClasses } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/admin/lateness';

type Filters = {
    status: string;
    role: string | null;
    class: number | null;
    from: string | null;
    to: string | null;
    search: string;
};

type LatenessReportPageProps = {
    report: LatenessDetail;
    filters: Filters;
    neighbours: {
        previous: number | null;
        next: number | null;
        position: number | null;
        total: number;
    };
};

/** The list's filters as a query string, dropping empty and default values. */
function listQuery(filters: Filters) {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([key, value]) =>
                value !== null &&
                value !== '' &&
                !(key === 'status' && value === 'new'),
        ),
    );
}

export default function LatenessReportPage({
    report,
    filters,
    neighbours,
}: LatenessReportPageProps) {
    const query = listQuery(filters);
    const stepClass = (enabled: boolean) =>
        cn(
            buttonClasses('outline', 'sm'),
            !enabled && 'pointer-events-none opacity-40',
        );

    return (
        <AppLayout
            title="Lateness report"
            subtitle="Attendance"
            actions={
                <nav
                    aria-label="Other reports in this list"
                    className="flex items-center gap-2"
                >
                    {neighbours.position !== null && (
                        <span className="text-[0.8125rem] text-muted-foreground tabular-nums">
                            {neighbours.position} of {neighbours.total}
                        </span>
                    )}
                    <Link
                        href={
                            neighbours.previous
                                ? show(neighbours.previous, { query })
                                : '#'
                        }
                        aria-disabled={neighbours.previous === null}
                        tabIndex={neighbours.previous === null ? -1 : undefined}
                        className={stepClass(neighbours.previous !== null)}
                    >
                        <Icon name="arrow-left" className="size-4" />
                        Previous
                    </Link>
                    <Link
                        href={
                            neighbours.next
                                ? show(neighbours.next, { query })
                                : '#'
                        }
                        aria-disabled={neighbours.next === null}
                        tabIndex={neighbours.next === null ? -1 : undefined}
                        className={stepClass(neighbours.next !== null)}
                    >
                        Next
                        <Icon name="arrow-right" className="size-4" />
                    </Link>
                </nav>
            }
        >
            <Link
                href={index({ query })}
                className="-mt-2 inline-flex items-center gap-1.5 self-start text-sm font-semibold text-link hover:underline sm:-mt-3"
            >
                <Icon name="arrow-left" className="size-4" />
                All lateness reports
            </Link>

            <LatenessReportDetail key={report.id} report={report} />
        </AppLayout>
    );
}
