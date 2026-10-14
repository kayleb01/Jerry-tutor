import { Form } from '@inertiajs/react';
import QuestionFields, { emptyQuestion } from '@/components/question-fields';
import type { QuestionFormOptions } from '@/components/question-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/admin/questions';

export default function CreateQuestion({
    options,
}: {
    options: QuestionFormOptions;
}) {
    return (
        <AppLayout title="New question" subtitle="Question bank">
            <Form {...store.form()} className="flex flex-col gap-4">
                {({ errors, processing }) => (
                    <>
                        <QuestionFields
                            initial={emptyQuestion}
                            options={options}
                            errors={errors}
                        />
                        <div>
                            <Button type="submit" disabled={processing}>
                                Save as draft
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AppLayout>
    );
}
