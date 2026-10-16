import { Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';

export default function ReportCaughtUp() {
    return (
        <AppLayout title="End-of-class report">
            <p className="text-body">You're all caught up</p>
            <Link href={dashboard()} className="text-sm underline">
                Back to your dashboard
            </Link>
        </AppLayout>
    );
}
