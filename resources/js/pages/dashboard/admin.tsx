import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

export default function AdminDashboard() {
    return (
        <AppLayout title="Admin dashboard">
            <Card>
                <CardHeader>
                    <CardTitle>Nothing to manage yet</CardTitle>
                    <CardDescription>
                        Users, classes, lateness reports and the question bank
                        will appear here.
                    </CardDescription>
                </CardHeader>
                <CardContent />
            </Card>
        </AppLayout>
    );
}
