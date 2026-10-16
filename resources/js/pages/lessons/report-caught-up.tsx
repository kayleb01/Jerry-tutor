import { Link } from '@inertiajs/react';
import { Card } from '@/components/ui/card';
import { buttonClasses } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';

export default function ReportCaughtUp() {
    return (
        <AppLayout title="End-of-class report">
            <Card className="flex max-w-xl flex-col items-start gap-4 p-card">
                <div>
                    <h2 className="text-section font-bold">
                        You're all caught up
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Every class you've taught has its report.
                    </p>
                </div>
                <Link href={dashboard()} className={buttonClasses('primary')}>
                    Back to your dashboard
                </Link>
            </Card>
        </AppLayout>
    );
}
