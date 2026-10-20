import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import ClassSlotForm from '@/components/class-slot-form';
import type { ClassSlotRow } from '@/components/class-slot-form';
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
    destroy as removeSlot,
    store as addSlot,
    update as updateSlot,
} from '@/routes/admin/classes/slots';
import {
    destroy as withdrawStudent,
    store as enrolStudent,
} from '@/routes/admin/classes/students';
import { show as register } from '@/routes/lessons/register';
import type { Option } from '@/types';

export type EditableClassGroup = ClassGroupDefaults & {
    id: number;
    name: string;
};

type Student = {
    id: number;
    name: string;
    username: string | null;
    yearGroup: string | null;
    enrolledOn: string;
};

type ClassLesson = {
    id: number;
    when: string;
    status: string;
    recorded: number;
};

type EditClassProps = {
    classGroup: EditableClassGroup;
    slots: ClassSlotRow[];
    subjects: Option[];
    tutors: Option[];
    weekdays: Option[];
    lessons: ClassLesson[];
    students: Student[];
    candidates: Option[];
    yearGroups: Option[];
};

export default function EditClass({
    classGroup,
    slots,
    subjects,
    tutors,
    weekdays,
    lessons,
    students,
    candidates,
    yearGroups,
}: EditClassProps) {
    const [editingSlot, setEditingSlot] = useState<number | null>(null);

    return (
        <AppLayout title={classGroup.name}>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
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
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Timetable</CardTitle>
                        <CardDescription>
                            Each slot is a subject taught weekly. Lessons are
                            scheduled from its day, time and tutor.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        {slots.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No timetable yet. Add a slot below.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {slots.map((slot) => (
                                    <li key={slot.id} className="py-3">
                                        {editingSlot === slot.id ? (
                                            <ClassSlotForm
                                                action={updateSlot.form([
                                                    classGroup.id,
                                                    slot.id,
                                                ])}
                                                idPrefix={`slot-${slot.id}`}
                                                subjects={subjects}
                                                tutors={tutors}
                                                weekdays={weekdays}
                                                slot={slot}
                                                submitLabel="Save slot"
                                                onDone={() =>
                                                    setEditingSlot(null)
                                                }
                                            />
                                        ) : (
                                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                                <p className="text-sm">
                                                    <span className="font-medium">
                                                        {slot.label}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {' · '}
                                                        {slot.subject}
                                                        {' · '}
                                                        {slot.tutor ??
                                                            'No tutor'}
                                                        {slot.location
                                                            ? ` · ${slot.location}`
                                                            : ''}
                                                    </span>
                                                </p>
                                                <div className="flex items-center gap-4 text-sm font-medium">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setEditingSlot(
                                                                slot.id,
                                                            )
                                                        }
                                                        className="text-link underline-offset-4 hover:underline"
                                                    >
                                                        Edit
                                                        <span className="sr-only">
                                                            {' '}
                                                            {slot.subject}{' '}
                                                            {slot.label}
                                                        </span>
                                                    </button>
                                                    <Link
                                                        href={removeSlot([
                                                            classGroup.id,
                                                            slot.id,
                                                        ])}
                                                        as="button"
                                                        onBefore={() =>
                                                            confirm(
                                                                `Remove ${slot.subject} ${slot.label}? Its upcoming lessons without attendance will be deleted.`,
                                                            )
                                                        }
                                                        className="text-danger underline-offset-4 hover:underline"
                                                    >
                                                        Remove
                                                        <span className="sr-only">
                                                            {' '}
                                                            {slot.subject}{' '}
                                                            {slot.label}
                                                        </span>
                                                    </Link>
                                                </div>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="flex flex-col gap-3 border-t pt-5">
                            <h3 className="text-sm font-semibold">Add slot</h3>
                            <ClassSlotForm
                                action={addSlot.form(classGroup.id)}
                                idPrefix="new-slot"
                                subjects={subjects}
                                tutors={tutors}
                                weekdays={weekdays}
                                submitLabel="Add slot"
                            />
                        </div>
                    </CardContent>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Lessons</CardTitle>
                        <CardDescription>
                            Recent and upcoming lessons. Open a register to view
                            or correct attendance.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {lessons.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No lessons scheduled. Give a slot a day, time
                                and tutor to schedule lessons.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {lessons.map((lesson) => (
                                    <li
                                        key={lesson.id}
                                        className="flex items-center justify-between gap-3 py-2 text-sm"
                                    >
                                        <span className="font-medium">
                                            {lesson.when}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {lesson.recorded} recorded
                                        </span>
                                        <Link
                                            href={register(lesson.id)}
                                            className="font-medium text-link underline-offset-4 hover:underline"
                                        >
                                            Register
                                            <span className="sr-only">
                                                {' '}
                                                for {lesson.when}
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
