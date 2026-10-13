import { Form } from '@inertiajs/react';
import ClassGroupFields from '@/components/class-group-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/admin/classes';
import type { Option } from '@/types';

type CreateClassProps = {
    tutors: Option[];
    weekdays: Option[];
    yearGroups: Option[];
};

export default function CreateClass({
    tutors,
    weekdays,
    yearGroups,
}: CreateClassProps) {
    return (
        <AppLayout title="Add class">
            <Card className="max-w-2xl">
                <CardContent>
                    <Form {...store.form()} className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <ClassGroupFields
                                    errors={errors}
                                    tutors={tutors}
                                    weekdays={weekdays}
                                    yearGroups={yearGroups}
                                />
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing ? 'Creating…' : 'Create class'}
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
