import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

export default function GuardianDashboard() {
    return (
        <AppLayout title="Your family">
            <Card>
                <CardHeader>
                    <CardTitle>Add your children</CardTitle>
                    <CardDescription>
                        You'll be able to add your children, see their
                        attendance and manage subscriptions here.
                    </CardDescription>
                </CardHeader>
                <CardContent />
            </Card>
        </AppLayout>
    );
}
