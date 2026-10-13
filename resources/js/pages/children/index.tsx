import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { attendance, create, edit } from '@/routes/children';

type Child = {
    id: number;
    name: string;
    username: string | null;
    email: string | null;
    yearGroup: string | null;
    attendance: number | null;
    late: number;
};

export default function ChildrenIndex({ children }: { children: Child[] }) {
    return (
        <AppLayout title="Your children" subtitle="Family">
            <div>
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center justify-center gap-2 rounded-sm bg-primary px-4 text-sm font-semibold text-primary-foreground hover:bg-primary-hover"
                >
                    Add a child
                </Link>
            </div>
            {children.length === 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle>No children added yet</CardTitle>
                        <CardDescription>
                            Add your child to create their login. They'll use
                            their username and password to sign in.
                        </CardDescription>
                    </CardHeader>
                    <CardContent />
                </Card>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2">
                    {children.map((child) => (
                        <li key={child.id}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>{child.name}</CardTitle>
                                    <CardDescription>
                                        Username:{' '}
                                        <span className="font-medium text-foreground">
                                            {child.username}
                                        </span>
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {child.yearGroup && (
                                            <Badge tone="brand">
                                                {child.yearGroup}
                                            </Badge>
                                        )}
                                        <span className="text-sm text-muted-foreground">
                                            {child.attendance === null
                                                ? 'No classes recorded yet'
                                                : `${child.attendance}% attendance · last 30 days`}
                                        </span>
                                        {child.late > 0 && (
                                            <Badge tone="late">
                                                {child.late} late
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="flex gap-4">
                                        <Link
                                            href={attendance(child.id)}
                                            className="text-sm font-medium text-link underline-offset-4 hover:underline"
                                        >
                                            View attendance
                                            <span className="sr-only">
                                                {' '}
                                                for {child.name}
                                            </span>
                                        </Link>
                                        <Link
                                            href={edit(child.id)}
                                            className="text-sm font-medium text-link underline-offset-4 hover:underline"
                                        >
                                            Edit
                                            <span className="sr-only">
                                                {' '}
                                                {child.name}
                                            </span>
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
