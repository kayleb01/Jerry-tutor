import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

export default function StudentDashboard() {
    return (
        <AppLayout title="Your dashboard">
            <Card>
                <CardHeader>
                    <CardTitle>Your quizzes are on the way</CardTitle>
                    <CardDescription>
                        Soon you'll find maths quizzes for your year group here.
                    </CardDescription>
                </CardHeader>
                <CardContent />
            </Card>
        </AppLayout>
    );
}
