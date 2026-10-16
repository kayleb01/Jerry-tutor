import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/icon';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { edit, update } from '@/routes/lessons/report';

type ReportProps = {
    lesson: { id: number; className: string; date: string; time: string };
    authorRole: 'tutor' | 'student';
    report: {
        rating: number | null;
        wentWell: string | null;
        issues: string | null;
        recommendations: string | null;
        recommendedActions: string[];
        raisesIssue: boolean;
        isDraft: boolean;
        submittedAt: string | null;
    } | null;
    closesAt: string;
    classOptions: { id: number; label: string }[] | null;
    recommendationOptions: { value: string; label: string }[];
    panel: {
        date: string;
        time: string;
        tutor: string | null;
        attendance: string;
    } | null;
};

const ratingWords = ['Poor', 'Below par', 'OK', 'Good', 'Excellent'] as const;

const prompts = {
    tutor: {
        wentWell: 'What went well?',
        issues: 'What could be better?',
    },
    student: {
        wentWell: 'What did you enjoy or understand well?',
        issues: 'Was anything difficult or a problem?',
    },
} as const;

function Star({ filled }: { filled: boolean }) {
    return (
        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
            className={cn(
                'size-5 sm:size-6',
                filled
                    ? 'fill-amber-400 stroke-amber-500'
                    : 'fill-none stroke-current',
            )}
            strokeWidth="1.75"
            strokeLinejoin="round"
        >
            <path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" />
        </svg>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-2.5 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium">{value}</dd>
        </div>
    );
}

export default function LessonReport({
    lesson,
    authorRole,
    report,
    closesAt,
    classOptions,
    recommendationOptions,
    panel,
}: ReportProps) {
    const isTutor = authorRole === 'tutor';
    const isSubmitted = report?.isDraft === false && !!report.submittedAt;
    const [rating, setRating] = useState<number | null>(report?.rating ?? null);

    return (
        <AppLayout title="How did the class go?" subtitle="End-of-class report">
            <p className="-mt-2 mb-4 max-w-2xl text-sm text-muted-foreground sm:mb-6 sm:text-base">
                Tutors and students can both give feedback and make
                recommendations after every class.
            </p>
            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-6">
                <Card className="p-card">
                    <Form
                        key={lesson.id}
                        {...update.form(lesson.id)}
                        className="flex flex-col gap-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                {isTutor && classOptions && (
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="class-picker">
                                            Class
                                        </Label>
                                        <Select
                                            id="class-picker"
                                            value={lesson.id}
                                            onChange={(event) =>
                                                router.visit(
                                                    edit(
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    ),
                                                )
                                            }
                                        >
                                            {classOptions.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </div>
                                )}

                                {!isTutor && (
                                    <p className="text-sm text-muted-foreground">
                                        {lesson.className} · {lesson.date},{' '}
                                        {lesson.time}.{' '}
                                        {report?.submittedAt &&
                                            `Last saved ${report.submittedAt}. `}
                                        You can change this until {closesAt}.
                                    </p>
                                )}

                                <fieldset
                                    className="flex flex-col gap-2"
                                    aria-describedby="rating-error"
                                >
                                    <legend className="text-sm font-medium">
                                        How well did the lesson go?
                                    </legend>
                                    <div className="flex items-center gap-3">
                                        <div className="flex gap-1.5 sm:gap-2">
                                            {[1, 2, 3, 4, 5].map((value) => (
                                                <label
                                                    key={value}
                                                    className={cn(
                                                        'inline-flex size-10 cursor-pointer items-center justify-center rounded-md border bg-surface text-muted-foreground has-focus-visible:outline-2 has-focus-visible:outline-ring sm:size-11',
                                                        rating !== null &&
                                                            value <= rating
                                                            ? 'border-amber-400 bg-amber-50 text-amber-500 dark:bg-amber-400/10'
                                                            : 'border-neutral-80 dark:border-neutral-30',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="rating"
                                                        value={value}
                                                        checked={
                                                            rating === value
                                                        }
                                                        onChange={() =>
                                                            setRating(value)
                                                        }
                                                        className="sr-only"
                                                    />
                                                    <Star
                                                        filled={
                                                            rating !== null &&
                                                            value <= rating
                                                        }
                                                    />
                                                    <span className="sr-only">
                                                        {value} of 5,{' '}
                                                        {ratingWords[value - 1]}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                        <span
                                            className="text-sm font-medium"
                                            aria-live="polite"
                                        >
                                            {rating !== null
                                                ? ratingWords[rating - 1]
                                                : ''}
                                        </span>
                                    </div>
                                    <FieldError
                                        id="rating-error"
                                        message={errors.rating}
                                    />
                                </fieldset>

                                {(
                                    [
                                        ['went_well', 'wentWell'],
                                        ['issues', 'issues'],
                                    ] as const
                                ).map(([name, key]) => (
                                    <div
                                        key={name}
                                        className="flex flex-col gap-1.5"
                                    >
                                        <Label htmlFor={name}>
                                            {prompts[authorRole][key]}
                                        </Label>
                                        <Textarea
                                            id={name}
                                            name={name}
                                            rows={4}
                                            defaultValue={
                                                (name === 'went_well'
                                                    ? report?.wentWell
                                                    : report?.issues) ?? ''
                                            }
                                            maxLength={2000}
                                            placeholder={
                                                name === 'went_well'
                                                    ? 'e.g. The group could name and measure angles confidently.'
                                                    : 'e.g. Two students need more practice with reflex angles.'
                                            }
                                            aria-invalid={!!errors[name]}
                                            aria-describedby={`${name}-error`}
                                        />
                                        <FieldError
                                            id={`${name}-error`}
                                            message={errors[name]}
                                        />
                                    </div>
                                ))}

                                <fieldset className="flex flex-col gap-3">
                                    <div>
                                        <legend className="text-sm font-medium">
                                            Recommendations
                                        </legend>
                                        <p className="text-[0.8125rem] text-muted-foreground">
                                            Pick any that apply, or write your
                                            own.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {recommendationOptions.map((option) => (
                                            <label
                                                key={option.value}
                                                className="group inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-neutral-80 bg-surface px-3.5 py-1.5 text-sm hover:bg-surface-muted has-checked:border-foreground has-checked:bg-foreground has-checked:text-background has-focus-visible:outline-2 has-focus-visible:outline-ring dark:border-neutral-30"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="recommended_actions[]"
                                                    value={option.value}
                                                    defaultChecked={report?.recommendedActions.includes(
                                                        option.value,
                                                    )}
                                                    className="sr-only"
                                                />
                                                <span
                                                    aria-hidden="true"
                                                    className="hidden group-has-checked:inline"
                                                >
                                                    ✓
                                                </span>
                                                {option.label}
                                            </label>
                                        ))}
                                    </div>
                                    <FieldError
                                        id="recommended-actions-error"
                                        message={errors.recommended_actions}
                                    />
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="recommendations">
                                            Another recommendation
                                        </Label>
                                        <Input
                                            id="recommendations"
                                            name="recommendations"
                                            defaultValue={
                                                report?.recommendations ?? ''
                                            }
                                            maxLength={2000}
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter') {
                                                    event.preventDefault();
                                                }
                                            }}
                                            aria-invalid={
                                                !!errors.recommendations
                                            }
                                            aria-describedby="recommendations-error"
                                        />
                                        <FieldError
                                            id="recommendations-error"
                                            message={errors.recommendations}
                                        />
                                    </div>
                                </fieldset>

                                {isTutor && (
                                    <label className="flex cursor-pointer items-start gap-3 rounded-md bg-surface-muted p-4">
                                        <input
                                            type="checkbox"
                                            name="raises_issue"
                                            value="1"
                                            defaultChecked={report?.raisesIssue}
                                            className="mt-0.5 size-4 shrink-0 accent-primary"
                                        />
                                        <span>
                                            <span className="block text-sm font-medium">
                                                Raise an issue with the academy
                                            </span>
                                            <span className="block text-[0.8125rem] text-muted-foreground">
                                                Flags this report for the admin
                                                team to follow up.
                                            </span>
                                        </span>
                                    </label>
                                )}

                                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end sm:gap-3">
                                    {!isSubmitted && (
                                        <Button
                                            type="submit"
                                            name="action"
                                            value="draft"
                                            variant="secondary"
                                            size="lg"
                                            disabled={processing}
                                        >
                                            Save draft
                                        </Button>
                                    )}
                                    <Button
                                        type="submit"
                                        name="action"
                                        value="submit"
                                        size="lg"
                                        disabled={processing}
                                    >
                                        Submit report
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Card>

                {isTutor && panel && (
                    <Card className="p-card">
                        <h2 className="text-base font-semibold sm:text-lg">
                            This class
                        </h2>
                        <dl className="mt-2 divide-y">
                            <Row label="Date" value={panel.date} />
                            <Row label="Time" value={panel.time} />
                            <Row label="Tutor" value={panel.tutor ?? '—'} />
                            <Row label="Attendance" value={panel.attendance} />
                        </dl>
                        <p className="mt-3 flex gap-2.5 rounded-md bg-primary-95 px-3.5 py-3 text-[0.8125rem] text-primary-30 dark:bg-primary-20 dark:text-primary-90">
                            <Icon
                                name="info"
                                className="mt-0.5 size-4 shrink-0"
                            />
                            Your report is shared with the academy admin team.
                        </p>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
