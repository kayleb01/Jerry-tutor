import { Form, Head } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { update } from '@/routes/password';

type ResetPasswordProps = {
    token: string;
    email: string;
};

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    return (
        <AuthLayout title="Choose a new password">
            <Head title="Reset password" />
            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={email}
                                readOnly
                                aria-invalid={!!errors.email}
                                aria-describedby="email-error"
                            />
                            <FieldError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password">New password</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                autoFocus
                                required
                                aria-invalid={!!errors.password}
                                aria-describedby="password-error"
                            />
                            <FieldError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password_confirmation">
                                Confirm new password
                            </Label>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required
                            />
                        </div>
                        <Button type="submit" size="lg" disabled={processing}>
                            Reset password
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
