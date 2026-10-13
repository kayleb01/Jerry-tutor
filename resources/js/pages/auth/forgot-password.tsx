import { Form, Head, Link } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status: string | null }) {
    return (
        <AuthLayout
            title="Forgot your password?"
            description="Enter your email and we'll send you a reset link. Students: ask your parent or tutor to reset yours."
        >
            <Head title="Forgot password" />
            {status && (
                <p className="mb-4 rounded-md bg-present-soft px-3 py-2 text-sm text-present">
                    {status}
                </p>
            )}
            <Form {...email.form()} className="flex flex-col gap-4">
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                autoFocus
                                required
                                aria-invalid={!!errors.email}
                                aria-describedby="email-error"
                            />
                            <FieldError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>
                        <Button type="submit" size="lg" disabled={processing}>
                            Email me a reset link
                        </Button>
                        <Link
                            href={login()}
                            className="text-center text-sm text-primary underline-offset-4 hover:underline"
                        >
                            Back to log in
                        </Link>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
