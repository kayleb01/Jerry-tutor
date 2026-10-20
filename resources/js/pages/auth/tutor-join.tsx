import { Form, Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/tutors/join';
import type { Option } from '@/types';

type TutorJoinProps = {
    classOptions: Option[];
    subjectOptions: Option[];
    formStarted: string;
};

const fields = [
    {
        name: 'name',
        label: 'Your full name',
        type: 'text',
        autoComplete: 'name',
    },
    {
        name: 'email',
        label: 'Email address',
        type: 'email',
        autoComplete: 'email',
    },
    {
        name: 'phone',
        label: 'Phone number',
        type: 'tel',
        autoComplete: 'tel',
    },
    {
        name: 'password',
        label: 'Password',
        type: 'password',
        autoComplete: 'new-password',
    },
    {
        name: 'password_confirmation',
        label: 'Confirm password',
        type: 'password',
        autoComplete: 'new-password',
    },
] as const;

export default function TutorJoin({
    classOptions,
    subjectOptions,
    formStarted,
}: TutorJoinProps) {
    const nextKey = useRef(1);
    const [rowKeys, setRowKeys] = useState<number[]>([0]);

    const addRow = () => {
        setRowKeys((keys) => [...keys, nextKey.current++]);
    };

    const removeRow = (key: number) => {
        setRowKeys((keys) => keys.filter((existing) => existing !== key));
    };

    return (
        <AuthLayout
            title="Apply to teach"
            description="Tell us who you are and which classes you'd like to teach. The academy team will review your application."
        >
            <Head title="Apply as a tutor" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        {errors.form && (
                            <p
                                role="alert"
                                className="bg-danger-soft rounded-md px-3 py-2 text-sm text-danger"
                            >
                                {errors.form}
                            </p>
                        )}
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
                                    required
                                    aria-invalid={!!errors[field.name]}
                                    aria-describedby={`${field.name}-error`}
                                />
                                <FieldError
                                    id={`${field.name}-error`}
                                    message={errors[field.name]}
                                />
                            </div>
                        ))}

                        <fieldset className="flex flex-col gap-3">
                            <legend className="mb-1 text-sm font-medium text-foreground">
                                Classes you'll teach
                            </legend>
                            <FieldError
                                id="classes-error"
                                message={errors.classes}
                            />
                            {rowKeys.map((key, index) => {
                                const classError =
                                    errors[`classes.${index}.class_group_id`];
                                const subjectError =
                                    errors[`classes.${index}.subject_id`];

                                return (
                                    <div
                                        key={key}
                                        className="flex flex-col gap-2 rounded-lg border border-border p-3"
                                    >
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor={`class-${key}`}>
                                                    Class
                                                </Label>
                                                <Select
                                                    id={`class-${key}`}
                                                    name={`classes[${index}][class_group_id]`}
                                                    defaultValue=""
                                                    required
                                                    aria-invalid={!!classError}
                                                    aria-describedby={`class-${key}-error`}
                                                >
                                                    <option value="" disabled>
                                                        Choose a class
                                                    </option>
                                                    {classOptions.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </Select>
                                                <FieldError
                                                    id={`class-${key}-error`}
                                                    message={classError}
                                                />
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                <Label
                                                    htmlFor={`subject-${key}`}
                                                >
                                                    Subject
                                                </Label>
                                                <Select
                                                    id={`subject-${key}`}
                                                    name={`classes[${index}][subject_id]`}
                                                    defaultValue=""
                                                    required
                                                    aria-invalid={
                                                        !!subjectError
                                                    }
                                                    aria-describedby={`subject-${key}-error`}
                                                >
                                                    <option value="" disabled>
                                                        Choose a subject
                                                    </option>
                                                    {subjectOptions.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </Select>
                                                <FieldError
                                                    id={`subject-${key}-error`}
                                                    message={subjectError}
                                                />
                                            </div>
                                        </div>
                                        <FieldError
                                            id={`row-${key}-error`}
                                            message={errors[`classes.${index}`]}
                                        />
                                        {rowKeys.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="self-end"
                                                onClick={() => removeRow(key)}
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                );
                            })}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={addRow}
                            >
                                Add another class
                            </Button>
                        </fieldset>

                        <div
                            aria-hidden="true"
                            className="absolute -left-[9999px] h-0 w-0 overflow-hidden"
                        >
                            <label htmlFor="website">Website</label>
                            <input
                                id="website"
                                name="website"
                                type="text"
                                tabIndex={-1}
                                autoComplete="off"
                                defaultValue=""
                            />
                        </div>
                        <input
                            type="hidden"
                            name="form_started"
                            value={formStarted}
                        />

                        <Button type="submit" size="lg" disabled={processing}>
                            {processing ? 'Sending…' : 'Apply'}
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
