import { Link } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as childrenIndex } from '@/routes/children';

export default function GuardianDashboard() {
    return (
        <AppLayout title="Your family">
            <Card>
                <CardHeader>
                    <CardTitle>Add your children</CardTitle>
                    <CardDescription>
                        Add your children so they can log in, and soon see their
                        attendance and quiz progress here.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Link
                        href={childrenIndex()}
                        className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-card hover:bg-primary-hover"
                    >
                        Manage children
                    </Link>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
