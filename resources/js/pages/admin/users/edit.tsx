import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/admin/users';
import type { Option } from '@/types';

export type EditableUser = {
    id: number;
    name: string;
    email: string | null;
    username: string | null;
    role: string;
    yearGroup: number | null;
    deactivated: boolean;
};

type EditUserProps = {
    user: EditableUser;
    yearGroups: Option[];
};

export default function EditUser({ user, yearGroups }: EditUserProps) {
    return (
        <AppLayout title={`Edit ${user.name}`}>
            {user.deactivated && (
                <div>
                    <Badge tone="absent">This account is deactivated</Badge>
                </div>
            )}
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form(user.id)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {user.role === 'student' ? (
                                        <StudentFields
                                            errors={errors}
                                            yearGroups={yearGroups}
                                            defaults={user}
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
                                                    defaultValue={user.name}
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
                                                <Label htmlFor="email">
                                                    Email
                                                </Label>
                                                <Input
                                                    id="email"
                                                    name="email"
                                                    type="email"
                                                    defaultValue={
                                                        user.email ?? ''
                                                    }
                                                    required
                                                    aria-invalid={
                                                        !!errors.email
                                                    }
                                                    aria-describedby="email-error"
                                                />
                                                <FieldError
                                                    id="email-error"
                                                    message={errors.email}
                                                />
                                            </div>
                                        </>
                                    )}
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || user.deactivated
                                        }
                                    >
                                        Save details
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
