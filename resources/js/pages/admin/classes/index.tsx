import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { create, edit } from '@/routes/admin/classes';

type Row = {
    id: number;
    name: string;
    tutor: string | null;
    schedule: string;
    students: number;
    nextLesson: string | null;
    isActive: boolean;
};

export default function ClassesIndex({ classes }: { classes: Row[] }) {
    return (
        <AppLayout title="Classes" subtitle="Scheduling">
            <div>
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center justify-center gap-2 rounded-sm bg-primary px-4 text-sm font-semibold text-primary-foreground hover:bg-primary-hover"
                >
                    Add class
                </Link>
            </div>
            <Card>
                <CardContent className="relative overflow-x-auto p-0">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                            <tr>
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
                                    Tutor
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Schedule
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Students
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Next lesson
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {classes.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-muted-foreground sm:py-8"
                                    >
                                        No classes yet. Add your first class to
                                        start scheduling lessons.
                                    </td>
                                </tr>
                            )}
                            {classes.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-4 py-3 font-semibold">
                                        {row.name}
                                        {!row.isActive && (
                                            <Badge
                                                tone="excused"
                                                className="ml-2"
                                            >
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.tutor ?? (
                                            <span className="text-muted-foreground">
                                                No tutor
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {row.schedule}
                                    </td>
                                    <td className="px-4 py-3 tabular-nums">
                                        {row.students}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {row.nextLesson ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={edit(row.id)}
                                            className="font-medium text-link underline-offset-4 hover:underline"
                                        >
                                            Edit
                                            <span className="sr-only">
                                                {' '}
                                                {row.name}
                                            </span>
                                        </Link>
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
