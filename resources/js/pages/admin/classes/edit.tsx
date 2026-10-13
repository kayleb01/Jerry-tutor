import { Form, Link } from '@inertiajs/react';
import ClassGroupFields from '@/components/class-group-fields';
import type { ClassGroupDefaults } from '@/components/class-group-fields';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/admin/classes';
import {
    destroy as withdrawStudent,
    store as enrolStudent,
} from '@/routes/admin/classes/students';
import type { Option } from '@/types';

export type EditableClassGroup = ClassGroupDefaults & {
    id: number;
    name: string;
    schedule: string;
};

type Student = {
    id: number;
    name: string;
    username: string | null;
    yearGroup: string | null;
    enrolledOn: string;
};

type EditClassProps = {
    classGroup: EditableClassGroup;
    students: Student[];
    candidates: Option[];
    tutors: Option[];
    weekdays: Option[];
    yearGroups: Option[];
};

export default function EditClass({
    classGroup,
    students,
    candidates,
    tutors,
    weekdays,
    yearGroups,
}: EditClassProps) {
    return (
        <AppLayout title={classGroup.name}>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                        <CardDescription>
                            {classGroup.schedule}. Changing the day, time,
                            length or tutor updates upcoming lessons.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form(classGroup.id)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <ClassGroupFields
                                        errors={errors}
                                        tutors={tutors}
                                        weekdays={weekdays}
                                        yearGroups={yearGroups}
                                        defaults={classGroup}
                                    />
                                    <Button type="submit" disabled={processing}>
                                        Save class
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Students ({students.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <Form
                            {...enrolStudent.form(classGroup.id)}
                            resetOnSuccess
                            className="flex items-end gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex flex-1 flex-col gap-1.5">
                                        <Label htmlFor="student_id">
                                            Add a student
                                        </Label>
                                        <Select
                                            id="student_id"
                                            name="student_id"
                                            defaultValue=""
                                            required
                                            aria-invalid={!!errors.student_id}
                                            aria-describedby="student_id-error"
                                        >
                                            <option value="" disabled>
                                                Choose a student
                                            </option>
                                            {candidates.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </Select>
                                        <FieldError
                                            id="student_id-error"
                                            message={errors.student_id}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={
                                            processing ||
                                            candidates.length === 0
                                        }
                                    >
                                        Add
                                    </Button>
                                </>
                            )}
                        </Form>
                        {students.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No students in this class yet.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {students.map((student) => (
                                    <li
                                        key={student.id}
                                        className="flex items-center justify-between gap-3 py-2"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {student.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {student.username}
                                                {student.yearGroup
                                                    ? ` · ${student.yearGroup}`
                                                    : ''}{' '}
                                                · joined {student.enrolledOn}
                                            </p>
                                        </div>
                                        <Link
                                            href={withdrawStudent([
                                                classGroup.id,
                                                student.id,
                                            ])}
                                            as="button"
                                            onBefore={() =>
                                                confirm(
                                                    `Remove ${student.name} from this class?`,
                                                )
                                            }
                                            className="text-sm font-medium text-danger underline-offset-4 hover:underline"
                                        >
                                            Remove
                                            <span className="sr-only">
                                                {' '}
                                                {student.name}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
