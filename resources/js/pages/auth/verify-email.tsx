import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status: string | null }) {
    return (
        <AuthLayout
            title="Check your email"
            description="We've sent you a link to confirm your email address. Click it to continue."
        >
            <Head title="Verify email" />
            {status === 'verification-link-sent' && (
                <p
                    role="status"
                    className="mb-4 rounded-md bg-present-soft px-3 py-2 text-sm text-present"
                >
                    A new link is on its way to your inbox.
                </p>
            )}
            <Form {...send.form()} className="flex flex-col gap-3">
                {({ processing }) => (
                    <>
                        <Button type="submit" disabled={processing}>
                            Resend the link
                        </Button>
                        <Link
                            href={logout()}
                            as="button"
                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            Log out
                        </Link>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
