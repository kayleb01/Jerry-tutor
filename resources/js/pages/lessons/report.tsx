import { Form } from '@inertiajs/react';
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
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/lessons/report';

type ReportProps = {
    lesson: { id: number; className: string; date: string; time: string };
    authorRole: 'tutor' | 'student';
    report: {
        rating: number | null;
        wentWell: string | null;
        issues: string | null;
        recommendations: string | null;
        submittedAt: string;
    } | null;
    closesAt: string;
};

const textarea =
    'min-h-24 w-full rounded-md border border-input bg-surface px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground aria-invalid:border-danger';

const prompts = {
    tutor: {
        went_well: 'What went well in this lesson?',
        issues: 'Any issues or concerns?',
        recommendations: 'Recommendations for next time',
    },
    student: {
        went_well: 'What did you enjoy or understand well?',
        issues: 'Was anything difficult or a problem?',
        recommendations: 'What would help you next time?',
    },
} as const;

export default function LessonReport({
    lesson,
    authorRole,
    report,
    closesAt,
}: ReportProps) {
    const fields = [
        ['went_well', report?.wentWell],
        ['issues', report?.issues],
        ['recommendations', report?.recommendations],
    ] as const;

    return (
        <AppLayout
            title="End-of-class report"
            subtitle={`${lesson.className} · ${lesson.date}, ${lesson.time}`}
        >
            <Card className="max-w-2xl">
                <CardHeader>
                    <CardTitle>
                        {report ? 'Edit your report' : 'How did it go?'}
                    </CardTitle>
                    <CardDescription>
                        {report ? `Last saved ${report.submittedAt}. ` : ''}You
                        can change this until {closesAt}. Only you and the
                        academy team can read it.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        {...update.form(lesson.id)}
                        className="flex flex-col gap-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <fieldset
                                    className="flex flex-col gap-2"
                                    aria-describedby="rating-hint rating-error"
                                >
                                    <legend className="text-sm font-medium">
                                        Overall rating (optional)
                                    </legend>
                                    <div className="flex flex-wrap gap-2">
                                        {[1, 2, 3, 4, 5].map((value) => (
                                            <label
                                                key={value}
                                                className="inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-input text-sm font-semibold has-checked:border-primary has-checked:bg-royal-50 has-focus-visible:outline-2 has-focus-visible:outline-ring dark:has-checked:bg-royal-900/40"
                                            >
                                                <input
                                                    type="radio"
                                                    name="rating"
                                                    value={value}
                                                    defaultChecked={
                                                        report?.rating === value
                                                    }
                                                    className="sr-only"
                                                />
                                                {value}
                                            </label>
                                        ))}
                                    </div>
                                    <p
                                        id="rating-hint"
                                        className="text-xs text-muted-foreground"
                                    >
                                        1 = poor, 5 = excellent
                                    </p>
                                    <FieldError
                                        id="rating-error"
                                        message={errors.rating}
                                    />
                                </fieldset>
                                {fields.map(([name, value]) => (
                                    <div
                                        key={name}
                                        className="flex flex-col gap-1.5"
                                    >
                                        <Label htmlFor={name}>
                                            {prompts[authorRole][name]}
                                        </Label>
                                        <textarea
                                            id={name}
                                            name={name}
                                            defaultValue={value ?? ''}
                                            maxLength={2000}
                                            className={textarea}
                                            aria-invalid={!!errors[name]}
                                            aria-describedby={`${name}-error`}
                                        />
                                        <FieldError
                                            id={`${name}-error`}
                                            message={errors[name]}
                                        />
                                    </div>
                                ))}
                                <div>
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={processing}
                                    >
                                        {processing ? 'Saving…' : 'Save report'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
