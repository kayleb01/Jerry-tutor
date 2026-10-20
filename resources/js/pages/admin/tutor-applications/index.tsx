import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/admin/tutor-applications';
import { index as usersIndex } from '@/routes/admin/users';
import type { Paginated } from '@/types';

type Status = 'pending' | 'rejected';

type Application = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    appliedOn: string;
    classes: string[];
};

type TutorApplicationsProps = {
    applications: Paginated<Application>;
    filters: { status: Status };
    counts: Record<Status, number>;
};

const statuses: { value: Status; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'rejected', label: 'Rejected' },
];

const chipClass = (active: boolean) =>
    cn(
        'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-[0.8125rem] font-semibold whitespace-nowrap transition-colors',
        active
            ? 'border-primary bg-primary text-primary-foreground'
            : 'bg-surface text-foreground hover:bg-surface-muted',
    );

export default function TutorApplications({
    applications,
    filters,
    counts,
}: TutorApplicationsProps) {
    return (
        <AppLayout title="Pending tutors" subtitle="Accounts">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <nav aria-label="Application status" className="flex gap-2">
                    {statuses.map((status) => (
                        <Link
                            key={status.value}
                            href={index({
                                query:
                                    status.value === 'pending'
                                        ? {}
                                        : { status: status.value },
                            })}
                            preserveScroll
                            aria-current={
                                filters.status === status.value
                                    ? 'page'
                                    : undefined
                            }
                            className={chipClass(
                                filters.status === status.value,
                            )}
                        >
                            {status.label}
                            <span className="tabular-nums opacity-75">
                                · {counts[status.value]}
                            </span>
                        </Link>
                    ))}
                </nav>
                <Link
                    href={usersIndex()}
                    className="text-sm font-medium text-link underline-offset-4 hover:underline"
                >
                    All users
                </Link>
            </div>
            <Card>
                <CardContent className="p-0">
                    {applications.data.length === 0 ? (
                        <p className="px-4 py-6 text-center text-sm text-muted-foreground sm:py-8">
                            {filters.status === 'pending'
                                ? 'No applications are waiting. Tutors appear here once they have verified their email.'
                                : 'No rejected applications.'}
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {applications.data.map((application) => (
                                <li
                                    key={application.id}
                                    className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <p className="font-semibold">
                                            {application.name}
                                        </p>
                                        <p className="text-sm break-words text-muted-foreground">
                                            {application.email}
                                            {application.phone
                                                ? ` · ${application.phone}`
                                                : ''}{' '}
                                            · applied {application.appliedOn}
                                        </p>
                                        <p className="mt-1 text-sm">
                                            {application.classes.join(', ')}
                                        </p>
                                    </div>
                                    <Link
                                        href={show(application.id)}
                                        className="inline-flex h-10 shrink-0 items-center justify-center rounded-md border border-input px-4 text-sm font-semibold text-foreground hover:bg-surface-muted"
                                    >
                                        Review
                                        <span className="sr-only">
                                            {' '}
                                            {application.name}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
            {applications.last_page > 1 && (
                <nav
                    className="flex items-center justify-between text-sm"
                    aria-label="Pagination"
                >
                    <span className="text-muted-foreground">
                        Page {applications.current_page} of{' '}
                        {applications.last_page}
                    </span>
                    <div className="flex gap-3">
                        {applications.prev_page_url && (
                            <Link
                                href={applications.prev_page_url}
                                className="font-medium text-link"
                            >
                                Previous
                            </Link>
                        )}
                        {applications.next_page_url && (
                            <Link
                                href={applications.next_page_url}
                                className="font-medium text-link"
                            >
                                Next
                            </Link>
                        )}
                    </div>
                </nav>
            )}
        </AppLayout>
    );
}
