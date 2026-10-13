import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/children';
import { update as updatePassword } from '@/routes/children/password';
import type { Option } from '@/types';

type EditChildProps = {
    child: {
        id: number;
        name: string;
        username: string | null;
        email: string | null;
        yearGroup: number | null;
    };
    yearGroups: Option[];
};

export default function EditChild({ child, yearGroups }: EditChildProps) {
    return (
        <AppLayout title={`Edit ${child.name}`}>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form(child.id)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <StudentFields
                                        errors={errors}
                                        yearGroups={yearGroups}
                                        defaults={child}
                                    />
                                    <Button type="submit" disabled={processing}>
                                        Save details
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Change password</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...updatePassword.form(child.id)}
                            resetOnSuccess
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="new_password">
                                            New password
                                        </Label>
                                        <Input
                                            id="new_password"
                                            name="password"
                                            type="password"
                                            autoComplete="new-password"
                                            required
                                            aria-invalid={!!errors.password}
                                            aria-describedby="new_password-error"
                                        />
                                        <FieldError
                                            id="new_password-error"
                                            message={errors.password}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="new_password_confirmation">
                                            Confirm new password
                                        </Label>
                                        <Input
                                            id="new_password_confirmation"
                                            name="password_confirmation"
                                            type="password"
                                            autoComplete="new-password"
                                            required
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Change password
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
