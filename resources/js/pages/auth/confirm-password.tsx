import { Form, Head } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <AuthLayout
            title="Confirm your password"
            description="For your security, please enter your password to continue."
        >
            <Head title="Confirm password" />
            <Form
                {...store.form()}
                resetOnSuccess
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
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
                        <Button type="submit" size="lg" disabled={processing}>
                            Confirm
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
