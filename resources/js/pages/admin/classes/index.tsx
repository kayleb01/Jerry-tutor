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
        <AppLayout title="Classes">
            <div>
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                >
                    Add class
                </Link>
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
                                    Class
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Tutor
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Schedule
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Students
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Next lesson
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
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
                                        className="px-4 py-8 text-center text-muted-foreground"
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
                                    <td className="px-4 py-3 font-medium">
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
                                            className="font-medium text-primary underline-offset-4 hover:underline"
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
