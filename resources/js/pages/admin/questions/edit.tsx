import { Form } from '@inertiajs/react';
import QuestionFields from '@/components/question-fields';
import type {
    QuestionFormOptions,
    QuestionFormValues,
} from '@/components/question-fields';
import QuestionStatusBadge from '@/components/question-status-badge';
import type { QuestionStatus } from '@/components/question-status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { destroy, update } from '@/routes/admin/questions';
import { update as statusUpdate } from '@/routes/admin/questions/status';
import type { Option } from '@/types';

export type EditableQuestion = QuestionFormValues & {
    id: number;
    status: QuestionStatus;
    statusLabel: string;
    nextStatuses: Option[];
    author: string | null;
    reviewedBy: string | null;
    publishedAt: string | null;
    canDelete: boolean;
};

const statusActions: Record<
    string,
    { label: string; variant: 'primary' | 'outline' }
> = {
    in_review: { label: 'Send for review', variant: 'primary' },
    published: { label: 'Publish', variant: 'primary' },
    draft: { label: 'Back to draft', variant: 'outline' },
    retired: { label: 'Retire', variant: 'outline' },
};

type EditQuestionProps = {
    question: EditableQuestion;
    options: QuestionFormOptions;
};

export default function EditQuestion({ question, options }: EditQuestionProps) {
    return (
        <AppLayout title={`Question #${question.id}`} subtitle="Question bank">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                    <QuestionStatusBadge
                        status={question.status}
                        label={question.statusLabel}
                    />
                    {question.author && (
                        <span>Written by {question.author}</span>
                    )}
                    {question.publishedAt && (
                        <span>
                            · Published {question.publishedAt}
                            {question.reviewedBy &&
                                ` by ${question.reviewedBy}`}
                        </span>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    {question.nextStatuses.map((next) => {
                        const action = statusActions[String(next.value)];

                        return (
                            <Form
                                key={next.value}
                                {...statusUpdate.form(question.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="status"
                                            value={next.value}
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant={
                                                action?.variant ?? 'outline'
                                            }
                                            disabled={processing}
                                        >
                                            {action?.label ?? next.label}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        );
                    })}
                </div>
            </div>

            <Form
                action={update.url(question.id)}
                method="post"
                options={{ preserveScroll: true }}
                className="flex flex-col gap-4"
            >
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="_method" value="put" />
                        <QuestionFields
                            key={question.id}
                            initial={question}
                            options={options}
                            errors={errors}
                        />
                        <div>
                            <Button type="submit" disabled={processing}>
                                Save changes
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            {question.canDelete && (
                <Form
                    {...destroy.form(question.id)}
                    onBefore={() => confirm('Delete this question?')}
                >
                    <Button type="submit" variant="danger" size="sm">
                        Delete question
                    </Button>
                </Form>
            )}
        </AppLayout>
    );
}
