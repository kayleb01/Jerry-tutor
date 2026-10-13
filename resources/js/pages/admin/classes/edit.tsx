import { Form } from '@inertiajs/react';
import ClassGroupFields from '@/components/class-group-fields';
import type { ClassGroupDefaults } from '@/components/class-group-fields';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/admin/classes';
import type { Option } from '@/types';

export type EditableClassGroup = ClassGroupDefaults & {
    id: number;
    name: string;
    schedule: string;
};

type EditClassProps = {
    classGroup: EditableClassGroup;
    tutors: Option[];
    weekdays: Option[];
    yearGroups: Option[];
};

export default function EditClass({
    classGroup,
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
            </div>
        </AppLayout>
    );
}
