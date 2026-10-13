import { Form, Link, router } from '@inertiajs/react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import LatenessFollowUp from '@/components/lateness-follow-up';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { exportMethod as exportCsv, index } from '@/routes/admin/lateness';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    date: string;
    name: string;
    role: 'student' | 'tutor';
    className: string;
    scheduled: string;
    arrived: string;
    minutesLate: number;
    followUpNotes: string | null;
    reviewedBy: string | null;
    reviewedAt: string | null;
};

type Repeat = {
    userId: number;
    name: string;
    role: 'student' | 'tutor';
    count: number;
    lastDate: string;
};

type Filters = {
    role: string | null;
    class: number | null;
    from: string | null;
    to: string | null;
    search: string;
};

type LatenessProps = {
    reports: Paginated<Row>;
    filters: Filters;
    classes: Option[];
    repeat: Repeat[];
};

const roleBadge = (role: Row['role']) => (
    <Badge tone={role === 'tutor' ? 'brand' : 'neutral'}>
        {role === 'tutor' ? 'Tutor' : 'Student'}
    </Badge>
);

export default function Lateness({
    reports,
    filters,
    classes,
    repeat,
}: LatenessProps) {
    const query = Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== null && value !== '',
        ),
    ) as Record<string, string | number>;

    return (
        <AppLayout title="Lateness">
            {repeat.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>Repeat lateness · last 30 days</CardTitle>
                        <CardDescription>
                            Late three or more times. Select a name to see their
                            reports.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y">
                            {repeat.map((person) => (
                                <li
                                    key={`${person.userId}-${person.role}`}
                                    className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm"
                                >
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.get(
                                                index.url({
                                                    query: {
                                                        search: person.name,
                                                    },
                                                }),
                                            )
                                        }
                                        className="flex items-center gap-2 font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        {person.name}
                                    </button>
                                    <span className="flex items-center gap-3">
                                        {roleBadge(person.role)}
                                        <Badge tone="late">
                                            {person.count} times
                                        </Badge>
                                        <span className="text-muted-foreground">
                                            last {person.lastDate}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}

            <div className="flex flex-wrap items-end justify-between gap-4">
                <Form
                    {...index.form()}
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="search">Name</Label>
                        <Input
                            id="search"
                            name="search"
                            defaultValue={filters.search}
                            className="w-48"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="role">Role</Label>
                        <Select
                            id="role"
                            name="role"
                            defaultValue={filters.role ?? ''}
                            className="w-36"
                        >
                            <option value="">Everyone</option>
                            <option value="student">Students</option>
                            <option value="tutor">Tutors</option>
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="class">Class</Label>
                        <Select
                            id="class"
                            name="class"
                            defaultValue={filters.class ?? ''}
                            className="w-48"
                        >
                            <option value="">All classes</option>
                            {classes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            name="from"
                            type="date"
                            defaultValue={filters.from ?? ''}
                            className="w-40"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            name="to"
                            type="date"
                            defaultValue={filters.to ?? ''}
                            className="w-40"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Filter
                    </Button>
                </Form>
                <a
                    href={exportCsv.url({ query })}
                    download
                    className="inline-flex h-10 items-center rounded-md border border-input bg-surface px-4 text-sm font-medium hover:bg-surface-muted"
                >
                    Export CSV
                </a>
            </div>

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
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
                                    Who
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
                                    Start → arrived
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Follow-up
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {reports.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No lateness recorded for these filters.
                                    </td>
                                </tr>
                            )}
                            {reports.data.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b align-top last:border-0"
                                >
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {row.date}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="flex flex-wrap items-center gap-2 font-medium">
                                            {row.name}
                                            {roleBadge(row.role)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.className}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className="tabular-nums">
                                            {row.scheduled} → {row.arrived}
                                        </span>{' '}
                                        <AttendanceStatusBadge
                                            status="late"
                                            minutesLate={row.minutesLate}
                                        />
                                    </td>
                                    <td className="min-w-64 px-4 py-3">
                                        <LatenessFollowUp
                                            reportId={row.id}
                                            name={row.name}
                                            notes={row.followUpNotes}
                                            reviewedBy={row.reviewedBy}
                                            reviewedAt={row.reviewedAt}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <nav
                className="flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <span className="text-muted-foreground">
                    {reports.total} reports · page {reports.current_page} of{' '}
                    {reports.last_page}
                </span>
                <div className="flex gap-3">
                    {reports.prev_page_url && (
                        <Link
                            href={reports.prev_page_url}
                            preserveState
                            className="font-medium text-primary"
                        >
                            Previous
                        </Link>
                    )}
                    {reports.next_page_url && (
                        <Link
                            href={reports.next_page_url}
                            preserveState
                            className="font-medium text-primary"
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </AppLayout>
    );
}
