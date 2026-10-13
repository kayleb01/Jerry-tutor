import { Form, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { index } from '@/routes/admin/lesson-reports';
import type { Option, Paginated } from '@/types';

type Report = {
    id: number;
    lessonDate: string;
    className: string;
    author: string;
    authorRole: 'tutor' | 'student';
    rating: number | null;
    wentWell: string | null;
    issues: string | null;
    recommendations: string | null;
};

type LessonReportsProps = {
    reports: Paginated<Report>;
    filters: {
        class: number | null;
        role: string | null;
        from: string | null;
        to: string | null;
    };
    classes: Option[];
};

const sections = [
    ['wentWell', 'What went well'],
    ['issues', 'Issues'],
    ['recommendations', 'Recommendations'],
] as const;

export default function LessonReports({
    reports,
    filters,
    classes,
}: LessonReportsProps) {
    return (
        <AppLayout title="End-of-class reports" subtitle="Attendance">
            <Form
                {...index.form()}
                options={{ preserveState: true }}
                className="flex flex-wrap items-end gap-3"
            >
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="class">Class</Label>
                    <Select
                        id="class"
                        name="class"
                        defaultValue={filters.class ?? ''}
                        className="w-52"
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
                    <Label htmlFor="role">Written by</Label>
                    <Select
                        id="role"
                        name="role"
                        defaultValue={filters.role ?? ''}
                        className="w-40"
                    >
                        <option value="">Everyone</option>
                        <option value="tutor">Tutors</option>
                        <option value="student">Students</option>
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

            {reports.data.length === 0 ? (
                <Card className="p-8 text-center text-muted-foreground">
                    No reports match these filters.
                </Card>
            ) : (
                <ul className="flex flex-col gap-4">
                    {reports.data.map((report) => (
                        <li key={report.id}>
                            <Card className="flex flex-col gap-4 p-5">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="font-semibold">
                                            {report.className}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {report.lessonDate}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium">
                                            {report.author}
                                        </span>
                                        <Badge
                                            tone={
                                                report.authorRole === 'tutor'
                                                    ? 'brand'
                                                    : 'neutral'
                                            }
                                        >
                                            {report.authorRole === 'tutor'
                                                ? 'Tutor'
                                                : 'Student'}
                                        </Badge>
                                        {report.rating !== null && (
                                            <Badge tone="gold">
                                                Rating {report.rating}/5
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                <dl className="grid gap-3 md:grid-cols-3">
                                    {sections.map(([key, label]) =>
                                        report[key] ? (
                                            <div key={key}>
                                                <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    {label}
                                                </dt>
                                                <dd className="mt-1 text-sm whitespace-pre-line">
                                                    {report[key]}
                                                </dd>
                                            </div>
                                        ) : null,
                                    )}
                                </dl>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}

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
                            className="font-medium text-link"
                        >
                            Previous
                        </Link>
                    )}
                    {reports.next_page_url && (
                        <Link
                            href={reports.next_page_url}
                            preserveState
                            className="font-medium text-link"
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </AppLayout>
    );
}
