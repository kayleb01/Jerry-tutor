import { Head, Link } from '@inertiajs/react';
import AuthLayout from '@/layouts/auth-layout';
import { logout } from '@/routes';

type TutorApplicationStatusProps = {
    status: 'pending' | 'rejected';
};

export default function TutorApplicationStatus({
    status,
}: TutorApplicationStatusProps) {
    const rejected = status === 'rejected';

    return (
        <AuthLayout
            title={
                rejected ? 'Application not approved' : 'Application received'
            }
            description={
                rejected
                    ? "Your application wasn't approved. Please contact the academy."
                    : "Thanks — your application is with the academy team. We'll email you when it's approved."
            }
        >
            <Head title="Tutor application" />
            <Link
                href={logout()}
                as="button"
                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                Log out
            </Link>
        </AuthLayout>
    );
}
