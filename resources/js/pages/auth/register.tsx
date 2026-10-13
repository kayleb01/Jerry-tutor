import { Form, Head, Link } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/register';

const fields = [
    {
        name: 'name',
        label: 'Your full name',
        type: 'text',
        autoComplete: 'name',
        required: true,
    },
    {
        name: 'email',
        label: 'Email address',
        type: 'email',
        autoComplete: 'email',
        required: true,
    },
    {
        name: 'phone',
        label: 'Phone number (optional)',
        type: 'tel',
        autoComplete: 'tel',
        required: false,
    },
    {
        name: 'password',
        label: 'Password',
        type: 'password',
        autoComplete: 'new-password',
        required: true,
    },
    {
        name: 'password_confirmation',
        label: 'Confirm password',
        type: 'password',
        autoComplete: 'new-password',
        required: true,
    },
] as const;

export default function Register() {
    return (
        <AuthLayout
            title="Create a parent account"
            description="You can add your children and subscribe once you're signed in."
        >
            <Head title="Create account" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        {fields.map((field) => (
                            <div
                                key={field.name}
                                className="flex flex-col gap-1.5"
                            >
                                <Label htmlFor={field.name}>
                                    {field.label}
                                </Label>
                                <Input
                                    id={field.name}
                                    name={field.name}
                                    type={field.type}
                                    autoComplete={field.autoComplete}
                                    required={field.required}
                                    aria-invalid={!!errors[field.name]}
                                    aria-describedby={`${field.name}-error`}
                                />
                                <FieldError
                                    id={`${field.name}-error`}
                                    message={errors[field.name]}
                                />
                            </div>
                        ))}
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing
                                ? 'Creating account…'
                                : 'Create account'}
                        </Button>
                        <p className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <Link
                                href={login()}
                                className="font-medium text-link underline-offset-4 hover:underline"
                            >
                                Log in
                            </Link>
                        </p>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
