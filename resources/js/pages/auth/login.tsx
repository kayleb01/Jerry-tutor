import { Form, Head, Link } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { register } from '@/routes';
import { join } from '@/routes/tutors';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type LoginProps = {
    status: string | null;
    canResetPassword: boolean;
    canRegister: boolean;
};

export default function Login({
    status,
    canResetPassword,
    canRegister,
}: LoginProps) {
    return (
        <AuthLayout
            title="Welcome back"
            description="Log in with your email, or your username if you're a student."
        >
            <Head title="Log in" />
            {status && (
                <p
                    role="status"
                    className="mb-4 rounded-md bg-present-soft px-3 py-2 text-sm text-present"
                >
                    {status}
                </p>
            )}
            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="login">Email or username</Label>
                            <Input
                                id="login"
                                name="login"
                                autoComplete="username"
                                autoFocus
                                required
                                aria-invalid={!!errors.login}
                                aria-describedby="login-error"
                            />
                            <FieldError
                                id="login-error"
                                message={errors.login}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && (
                                    <Link
                                        href={request()}
                                        className="text-sm text-link underline-offset-4 hover:underline"
                                    >
                                        Forgot password?
                                    </Link>
                                )}
                            </div>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                aria-invalid={!!errors.password}
                                aria-describedby="password-error"
                            />
                            <FieldError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="remember"
                                className="size-4 accent-primary"
                            />
                            Keep me logged in
                        </label>
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing ? 'Logging in…' : 'Log in'}
                        </Button>
                        <p className="text-center text-sm text-muted-foreground">
                            Joining as a tutor?{' '}
                            <Link
                                href={join()}
                                className="font-medium text-link underline-offset-4 hover:underline"
                            >
                                Apply here
                            </Link>
                        </p>
                        {canRegister && (
                            <p className="text-center text-sm text-muted-foreground">
                                New parent or guardian?{' '}
                                <Link
                                    href={register()}
                                    className="font-medium text-link underline-offset-4 hover:underline"
                                >
                                    Create an account
                                </Link>
                            </p>
                        )}
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
