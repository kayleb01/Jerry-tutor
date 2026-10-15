import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import MathText from '@/components/math-text';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { submit as submitAttempt } from '@/routes/quiz-attempts';
import { show, update } from '@/routes/quiz-attempts/questions';
import { index as quizzesIndex } from '@/routes/quizzes';

type QuestionType =
    | 'multiple_choice'
    | 'multi_select'
    | 'numeric'
    | 'short_text';

type SavedResponse =
    | { option: number }
    | { options: number[] }
    | { value: string }
    | { text: string }
    | null;

type PlayProps = {
    attempt: {
        id: number;
        quizTitle: string;
        position: number;
        total: number;
        answered: number[];
    };
    question: {
        type: QuestionType;
        stem: string;
        imageUrl: string | null;
        marks: number;
        options: { id: number; body: string }[];
        response: SavedResponse;
    };
};

type AnswerData = {
    option: number | null;
    options: number[];
    value: string;
    text: string;
};

const prompts: Record<QuestionType, string> = {
    multiple_choice: 'Choose one answer.',
    multi_select: 'Tick every answer that is right.',
    numeric: 'Type your answer as a number.',
    short_text: 'Type your answer.',
};

function initialAnswer(response: SavedResponse): AnswerData {
    return {
        option: response && 'option' in response ? response.option : null,
        options: response && 'options' in response ? response.options : [],
        value: response && 'value' in response ? response.value : '',
        text: response && 'text' in response ? response.text : '',
    };
}

export default function Play(props: PlayProps) {
    // A fresh form for every question, so one question's answer never leaks into the next.
    return <Player key={props.attempt.position} {...props} />;
}

function Player({ attempt, question }: PlayProps) {
    const form = useForm<AnswerData>(initialAnswer(question.response));
    const [confirmingFinish, setConfirmingFinish] = useState(false);
    const [finishing, setFinishing] = useState(false);
    const busy = form.processing || finishing;
    const { position, total } = attempt;
    const isLast = position >= total;

    const hasAnswer = {
        multiple_choice: form.data.option !== null,
        multi_select: form.data.options.length > 0,
        numeric: form.data.value.trim() !== '',
        short_text: form.data.text.trim() !== '',
    }[question.type];

    const unanswered = Array.from(
        { length: total },
        (_, index) => index + 1,
    ).filter(
        (item) =>
            !attempt.answered.includes(item) &&
            !(item === position && hasAnswer),
    );

    /** The answer in the shape the server expects for this question type. */
    function payload(stay: boolean) {
        form.transform((data) => {
            const answer = {
                multiple_choice: { option: data.option },
                multi_select: { options: data.options },
                numeric: { value: data.value },
                short_text: { text: data.text },
            }[question.type];

            return stay ? { ...answer, stay: true } : answer;
        });
    }

    /** Save a changed answer (staying on this question), then carry on. */
    function saveThen(next: () => void) {
        if (!hasAnswer || !form.isDirty) {
            next();

            return;
        }

        payload(true);
        form.put(update.url([attempt.id, position]), {
            preserveScroll: true,
            onSuccess: next,
        });
    }

    function goTo(target: number) {
        saveThen(() => router.visit(show([attempt.id, target])));
    }

    function next(event?: FormEvent) {
        event?.preventDefault();

        if (isLast) {
            requestFinish();

            return;
        }

        if (!hasAnswer) {
            router.visit(show([attempt.id, position + 1]));

            return;
        }

        payload(false);
        form.put(update.url([attempt.id, position]));
    }

    function requestFinish() {
        if (unanswered.length > 0 && !confirmingFinish) {
            setConfirmingFinish(true);

            return;
        }

        saveThen(() =>
            router.post(
                submitAttempt.url(attempt.id),
                {},
                {
                    onStart: () => setFinishing(true),
                    onFinish: () => setFinishing(false),
                },
            ),
        );
    }

    const error =
        form.errors.option ??
        form.errors.options ??
        form.errors.value ??
        form.errors.text;

    return (
        <AppLayout title={attempt.quizTitle} subtitle="Quiz">
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 sm:gap-5">
                <div className="flex flex-col gap-2.5">
                    <div className="flex items-center justify-between gap-3 text-sm">
                        <span className="font-semibold">
                            Question {position} of {total}
                        </span>
                        <button
                            type="button"
                            onClick={() =>
                                saveThen(() => router.visit(quizzesIndex()))
                            }
                            disabled={busy}
                            className="font-semibold text-link hover:underline disabled:opacity-40"
                        >
                            Save and leave
                        </button>
                    </div>
                    <div
                        className="h-2 overflow-hidden rounded-full bg-surface-muted"
                        role="progressbar"
                        aria-label="Answered"
                        aria-valuemin={0}
                        aria-valuemax={total}
                        aria-valuenow={total - unanswered.length}
                    >
                        <div
                            className="h-full rounded-full bg-primary transition-[width]"
                            style={{
                                width: `${((total - unanswered.length) / total) * 100}%`,
                            }}
                        />
                    </div>
                    <nav
                        aria-label="Questions"
                        className="flex flex-wrap gap-1.5"
                    >
                        {Array.from(
                            { length: total },
                            (_, index) => index + 1,
                        ).map((item) => {
                            const done = !unanswered.includes(item);

                            return (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() => goTo(item)}
                                    disabled={busy}
                                    aria-current={
                                        item === position ? 'step' : undefined
                                    }
                                    aria-label={`Question ${item}${done ? ', answered' : ''}`}
                                    className={cn(
                                        'flex size-8 items-center justify-center rounded-full text-xs font-semibold tabular-nums transition-colors',
                                        done
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-surface-muted text-muted-foreground hover:bg-neutral-90 dark:hover:bg-neutral-30',
                                        item === position &&
                                            'ring-2 ring-accent ring-offset-2 ring-offset-background',
                                    )}
                                >
                                    {item}
                                </button>
                            );
                        })}
                    </nav>
                </div>

                <form onSubmit={next} className="flex flex-col gap-4 sm:gap-5">
                    <Card className="flex flex-col gap-5 p-card sm:p-6">
                        <div className="flex flex-col gap-3">
                            <p className="text-[0.8125rem] font-semibold tracking-wide text-muted-foreground uppercase">
                                {question.marks}{' '}
                                {question.marks === 1 ? 'mark' : 'marks'}
                            </p>
                            <h2 className="text-lg leading-relaxed font-semibold sm:text-xl">
                                <MathText text={question.stem} />
                            </h2>
                            {question.imageUrl && (
                                <img
                                    src={question.imageUrl}
                                    alt=""
                                    className="max-h-80 self-start rounded-md border object-contain"
                                />
                            )}
                        </div>

                        <fieldset className="flex flex-col gap-3">
                            <legend className="mb-3 text-sm text-muted-foreground">
                                {prompts[question.type]}
                            </legend>
                            {question.type === 'multiple_choice' && (
                                <OptionTiles
                                    options={question.options}
                                    selected={
                                        form.data.option === null
                                            ? []
                                            : [form.data.option]
                                    }
                                    onToggle={(id) =>
                                        form.setData('option', id)
                                    }
                                />
                            )}
                            {question.type === 'multi_select' && (
                                <OptionTiles
                                    multiple
                                    options={question.options}
                                    selected={form.data.options}
                                    onToggle={(id) =>
                                        form.setData(
                                            'options',
                                            form.data.options.includes(id)
                                                ? form.data.options.filter(
                                                      (item) => item !== id,
                                                  )
                                                : [...form.data.options, id],
                                        )
                                    }
                                />
                            )}
                            {question.type === 'numeric' && (
                                <NumberAnswer
                                    value={form.data.value}
                                    onChange={(value) =>
                                        form.setData('value', value)
                                    }
                                    invalid={Boolean(error)}
                                />
                            )}
                            {question.type === 'short_text' && (
                                <Input
                                    id="answer"
                                    name="text"
                                    value={form.data.text}
                                    onChange={(event) =>
                                        form.setData('text', event.target.value)
                                    }
                                    maxLength={100}
                                    autoComplete="off"
                                    autoCapitalize="off"
                                    spellCheck={false}
                                    aria-label="Your answer"
                                    aria-invalid={Boolean(error)}
                                    aria-describedby={
                                        error ? 'answer-error' : undefined
                                    }
                                    className="text-lg sm:max-w-md"
                                />
                            )}
                            <FieldError id="answer-error" message={error} />
                        </fieldset>
                    </Card>

                    {confirmingFinish && unanswered.length > 0 && (
                        <Card
                            role="alert"
                            className="flex flex-col gap-3 border-accent/40 bg-accent/5 p-card sm:flex-row sm:items-center"
                        >
                            <p className="min-w-0 flex-1 text-sm">
                                You haven’t answered{' '}
                                {unanswered.length === 1
                                    ? `question ${unanswered[0]}`
                                    : `${unanswered.length} questions`}
                                . Blank answers are marked wrong.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => goTo(unanswered[0])}
                                    disabled={busy}
                                >
                                    Go to question {unanswered[0]}
                                </Button>
                                <Button
                                    variant="accent"
                                    onClick={requestFinish}
                                    disabled={busy}
                                >
                                    Finish anyway
                                </Button>
                            </div>
                        </Card>
                    )}

                    <div className="flex items-center justify-between gap-3">
                        <Button
                            variant="outline"
                            size="lg"
                            onClick={() => goTo(position - 1)}
                            disabled={position <= 1 || busy}
                        >
                            <Icon name="arrow-left" className="size-4" />
                            Previous
                        </Button>
                        <div className="flex items-center gap-2">
                            {!isLast && (
                                <Button
                                    variant="ghost"
                                    size="lg"
                                    className="hidden sm:inline-flex"
                                    onClick={requestFinish}
                                    disabled={busy}
                                >
                                    Finish
                                </Button>
                            )}
                            <Button
                                type="submit"
                                size="lg"
                                variant={isLast ? 'accent' : 'primary'}
                                disabled={busy}
                            >
                                {isLast
                                    ? 'Finish quiz'
                                    : hasAnswer
                                      ? 'Next'
                                      : 'Skip'}
                                <Icon
                                    name={isLast ? 'check' : 'arrow-right'}
                                    className="size-4"
                                />
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}

type OptionTilesProps = {
    options: { id: number; body: string }[];
    selected: number[];
    onToggle: (id: number) => void;
    multiple?: boolean;
};

function OptionTiles({
    options,
    selected,
    onToggle,
    multiple = false,
}: OptionTilesProps) {
    return (
        <div className="grid gap-2.5 sm:grid-cols-2 sm:gap-3">
            {options.map((option, index) => {
                const checked = selected.includes(option.id);

                return (
                    <label
                        key={option.id}
                        className={cn(
                            'flex min-h-14 cursor-pointer items-center gap-3 rounded-lg border-2 px-4 py-3 transition-colors focus-within:ring-3 focus-within:ring-ring/30',
                            checked
                                ? 'border-primary bg-primary/5'
                                : 'border-border hover:border-neutral-70 hover:bg-surface-muted',
                        )}
                    >
                        <input
                            type={multiple ? 'checkbox' : 'radio'}
                            name={multiple ? 'options[]' : 'option'}
                            value={option.id}
                            checked={checked}
                            onChange={() => onToggle(option.id)}
                            className="sr-only"
                        />
                        <span
                            aria-hidden="true"
                            className={cn(
                                'flex size-7 shrink-0 items-center justify-center text-sm font-bold transition-colors',
                                multiple ? 'rounded-md' : 'rounded-full',
                                checked
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-surface-muted text-muted-foreground',
                            )}
                        >
                            {multiple && checked ? (
                                <Icon name="check" className="size-4" />
                            ) : (
                                String.fromCharCode(65 + index)
                            )}
                        </span>
                        <MathText text={option.body} className="text-base" />
                    </label>
                );
            })}
        </div>
    );
}

const padKeys = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '-', '0', '.'];

type NumberAnswerProps = {
    value: string;
    onChange: (value: string) => void;
    invalid: boolean;
};

/** A text box for the keyboard, plus a big on-screen pad for fingers. */
function NumberAnswer({ value, onChange, invalid }: NumberAnswerProps) {
    const type = (key: string) => {
        if (value.length < 30) {
            onChange(value + key);
        }
    };

    return (
        <div className="flex flex-col gap-3 sm:max-w-xs">
            <Input
                id="answer"
                name="value"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                inputMode="decimal"
                maxLength={30}
                autoComplete="off"
                aria-label="Your answer"
                aria-invalid={invalid}
                aria-describedby={invalid ? 'answer-error' : undefined}
                className="h-14 text-center text-2xl font-semibold tabular-nums sm:h-14"
            />
            <div
                className="grid grid-cols-3 gap-2"
                aria-label="Number pad"
                role="group"
            >
                {padKeys.map((key) => (
                    <Button
                        key={key}
                        variant="secondary"
                        className="h-12 text-lg text-foreground tabular-nums sm:h-12"
                        onClick={() => type(key)}
                        aria-label={
                            key === '-' ? 'minus' : key === '.' ? 'point' : key
                        }
                    >
                        {key === '-' ? '−' : key}
                    </Button>
                ))}
                <Button
                    variant="secondary"
                    className="h-12 text-lg text-foreground sm:h-12"
                    onClick={() => type('/')}
                    aria-label="fraction bar"
                >
                    /
                </Button>
                <Button
                    variant="secondary"
                    className="col-span-2 h-12 text-foreground sm:h-12"
                    onClick={() => onChange(value.slice(0, -1))}
                    disabled={value === ''}
                >
                    <Icon name="arrow-left" className="size-4" />
                    Delete
                </Button>
            </div>
        </div>
    );
}
