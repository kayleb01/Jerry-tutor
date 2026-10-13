import { Link } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as adminClassesIndex } from '@/routes/admin/classes';
import { index as adminUsersIndex } from '@/routes/admin/users';

const linkClass =
    'inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover';

export default function AdminDashboard() {
    return (
        <AppLayout title="Admin dashboard">
            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Users</CardTitle>
                        <CardDescription>
                            Create tutor, student and parent accounts, and
                            deactivate ones that are no longer needed.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Link href={adminUsersIndex()} className={linkClass}>
                            Manage users
                        </Link>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Classes</CardTitle>
                        <CardDescription>
                            Set up recurring classes, assign tutors and enrol
                            students. Lessons are scheduled four weeks ahead.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Link href={adminClassesIndex()} className={linkClass}>
                            Manage classes
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
