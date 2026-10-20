import { Form, Link } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { index } from '@/routes/tutor/classes';
import { store } from '@/routes/tutor/classes/students';
import type { Option } from '@/types';

type AddStudentProps = {
    classGroup: { id: number; name: string; yearGroup: number | null };
    yearGroups: Option[];
};

export default function AddStudent({
    classGroup,
    yearGroups,
}: AddStudentProps) {
    return (
        <AppLayout
            title="Add student"
            subtitle={`They will be enrolled in ${classGroup.name}.`}
        >
            <Card className="max-w-xl">
                <CardContent>
                    <Form
                        {...store.form(classGroup.id)}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <StudentFields
                                    errors={errors}
                                    yearGroups={yearGroups}
                                    defaults={{
                                        yearGroup: classGroup.yearGroup,
                                    }}
                                />
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
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={processing}
                                    >
                                        {processing ? 'Adding…' : 'Add student'}
                                    </Button>
                                    <Link
                                        href={index()}
                                        className={buttonClasses(
                                            'outline',
                                            'lg',
                                        )}
                                    >
                                        Cancel
                                    </Link>
                                </div>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
