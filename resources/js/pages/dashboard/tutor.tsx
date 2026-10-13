import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

export default function TutorDashboard() {
    return (
        <AppLayout title="Today">
            <Card>
                <CardHeader>
                    <CardTitle>No classes scheduled</CardTitle>
                    <CardDescription>
                        Your classes, check-in and registers will appear here.
                    </CardDescription>
                </CardHeader>
                <CardContent />
            </Card>
        </AppLayout>
    );
}
