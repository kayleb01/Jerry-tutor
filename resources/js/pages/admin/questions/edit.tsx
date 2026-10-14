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
