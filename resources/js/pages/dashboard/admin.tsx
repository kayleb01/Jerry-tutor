import { Link } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as adminUsersIndex } from '@/routes/admin/users';

export default function AdminDashboard() {
    return (
        <AppLayout title="Admin dashboard">
            <Card>
                <CardHeader>
                    <CardTitle>Users</CardTitle>
                    <CardDescription>
                        Create tutor, student and parent accounts, and
                        deactivate ones that are no longer needed.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Link
                        href={adminUsersIndex()}
                        className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                    >
                        Manage users
                    </Link>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
