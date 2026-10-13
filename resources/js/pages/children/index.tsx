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
import { create, edit } from '@/routes/children';

type Child = {
    id: number;
    name: string;
    username: string | null;
    email: string | null;
    yearGroup: string | null;
};

export default function ChildrenIndex({ children }: { children: Child[] }) {
    return (
        <AppLayout title="Your children">
            <div>
                <Link
                    href={create()}
                    className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
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
                                <CardContent className="flex items-center justify-between">
                                    {child.yearGroup && (
                                        <Badge tone="brand">
                                            {child.yearGroup}
                                        </Badge>
                                    )}
                                    <Link
                                        href={edit(child.id)}
                                        className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        Edit
                                    </Link>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
