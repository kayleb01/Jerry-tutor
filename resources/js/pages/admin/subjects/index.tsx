import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { store, update } from '@/routes/admin/subjects';

type SubjectRow = {
    id: number;
    name: string;
    isActive: boolean;
};

type SubjectsProps = {
    subjects: SubjectRow[];
};

export default function Subjects({ subjects }: SubjectsProps) {
    return (
        <AppLayout title="Subjects" subtitle="Classes">
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Subjects are what each class slot on the timetable teaches.
                Deactivating a subject keeps its past lessons but hides it from
                new slots.
            </p>

            <Card className="divide-y divide-border">
                {subjects.map((subject) => (
                    <div
                        key={subject.id}
                        className="flex flex-wrap items-start gap-3 p-card"
                    >
                        <Form
                            {...update.form(subject.id)}
                            options={{ preserveScroll: true }}
                            className="flex min-w-0 flex-1 flex-wrap items-start gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex min-w-48 flex-1 flex-col gap-1.5">
                                        <Label
                                            htmlFor={`name-${subject.id}`}
                                            className="sr-only"
                                        >
                                            Name of {subject.name}
                                        </Label>
                                        <Input
                                            id={`name-${subject.id}`}
                                            name="name"
                                            defaultValue={subject.name}
                                            required
                                            aria-invalid={!!errors.name}
                                            aria-describedby={`name-${subject.id}-error`}
                                        />
                                        <FieldError
                                            id={`name-${subject.id}-error`}
                                            message={errors.name}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Rename
                                    </Button>
                                </>
                            )}
                        </Form>
                        <div className="flex items-center gap-3">
                            <Badge
                                tone={subject.isActive ? 'present' : 'neutral'}
                            >
                                {subject.isActive ? 'Active' : 'Inactive'}
                            </Badge>
                            <Form
                                {...update.form(subject.id)}
                                options={{ preserveScroll: true }}
                                transform={() => ({
                                    is_active: !subject.isActive,
                                })}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {subject.isActive
                                            ? 'Deactivate'
                                            : 'Activate'}
                                    </Button>
                                )}
                            </Form>
                        </div>
                    </div>
                ))}
            </Card>

            <Card className="p-card">
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <h2 className="text-section font-bold">
                                Add subject
                            </h2>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="new-subject">Name</Label>
                                <Input
                                    id="new-subject"
                                    name="name"
                                    required
                                    aria-invalid={!!errors.name}
                                    aria-describedby="new-subject-error"
                                />
                                <FieldError
                                    id="new-subject-error"
                                    message={errors.name}
                                />
                            </div>
                            <div>
                                <Button type="submit" disabled={processing}>
                                    Add subject
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Card>
        </AppLayout>
    );
}
