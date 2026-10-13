import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import StudentFields from '@/components/student-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/children';
import type { Option } from '@/types';

export default function CreateChild({ yearGroups }: { yearGroups: Option[] }) {
    return (
        <AppLayout title="Add a child">
            <Card className="max-w-xl">
                <CardContent>
                    <Form
                        {...store.form()}
                        resetOnSuccess
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <StudentFields
                                    errors={errors}
                                    yearGroups={yearGroups}
                                />
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="relationship">
                                        Your relationship (optional)
                                    </Label>
                                    <Input
                                        id="relationship"
                                        name="relationship"
                                        placeholder="e.g. Mother"
                                        aria-invalid={!!errors.relationship}
                                        aria-describedby="relationship-error"
                                    />
                                    <FieldError
                                        id="relationship-error"
                                        message={errors.relationship}
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password">
                                        Password for your child
                                    </Label>
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
                                    {processing ? 'Adding…' : 'Add child'}
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
