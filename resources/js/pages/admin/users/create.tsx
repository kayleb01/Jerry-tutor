import { Form } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/admin/users';
import type { Option } from '@/types';

type CreateUserProps = {
    roles: Option[];
    yearGroups: Option[];
};

export default function CreateUser({ roles, yearGroups }: CreateUserProps) {
    const [role, setRole] = useState('student');

    return (
        <AppLayout title="Add user">
            <Card className="max-w-xl">
                <CardContent>
                    <Form {...store.form()} className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="role">Role</Label>
                                    <Select
                                        id="role"
                                        name="role"
                                        value={role}
                                        onChange={(event) =>
                                            setRole(event.target.value)
                                        }
                                        aria-invalid={!!errors.role}
                                        aria-describedby="role-error"
                                    >
                                        {roles.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                    <FieldError
                                        id="role-error"
                                        message={errors.role}
                                    />
                                </div>
                                {role === 'student' ? (
                                    <StudentFields
                                        errors={errors}
                                        yearGroups={yearGroups}
                                    />
                                ) : (
                                    <>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="name">
                                                Full name
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                                aria-invalid={!!errors.name}
                                                aria-describedby="name-error"
                                            />
                                            <FieldError
                                                id="name-error"
                                                message={errors.name}
                                            />
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="email">Email</Label>
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                required
                                                aria-invalid={!!errors.email}
                                                aria-describedby="email-error"
                                            />
                                            <FieldError
                                                id="email-error"
                                                message={errors.email}
                                            />
                                        </div>
                                    </>
                                )}
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
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
                                        Confirm password
                                    </Label>
                                    <Input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing ? 'Creating…' : 'Create user'}
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
