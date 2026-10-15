import { Form, Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import MathText from '@/components/math-text';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as quizzesIndex } from '@/routes/quizzes';
import { store as startQuiz } from '@/routes/quizzes/attempts';

type ResultsProps = {
    attempt: {
        id: number;
        quizTitle: string;
        quizSlug: string;
        score: number;
        maxScore: number;
        percentage: number;
        duration: string | null;
        message: string;
    };
    questions: {
        position: number;
        type: string;
        stem: string;
        imageUrl: string | null;
        yourAnswer: string | null;
        correctAnswer: string;
        isCorrect: boolean;
        marks: number;
        marksAwarded: number | null;
        explanation: string | null;
    }[];
};

export default function Results({ attempt, questions }: ResultsProps) {
    const correct = questions.filter((question) => question.isCorrect).length;

    return (
        <AppLayout title={attempt.quizTitle} subtitle="Results">
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 sm:gap-5">
                <Card className="flex flex-col items-center gap-4 p-card text-center sm:flex-row sm:gap-6 sm:p-6 sm:text-left">
                    <ScoreRing percentage={attempt.percentage} />
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <p className="text-section font-bold">
                            {attempt.message}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            You scored{' '}
                            <span className="font-semibold text-foreground tabular-nums">
                                {attempt.score} out of {attempt.maxScore}
                            </span>
                            {' · '}
                            {correct} of {questions.length} questions right
                            {attempt.duration && ` · ${attempt.duration}`}
                        </p>
                    </div>
                    <div className="flex w-full flex-col gap-2 sm:w-auto">
                        <Form {...startQuiz.form(attempt.quizSlug)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    Try again
                                    <Icon
                                        name="arrow-right"
                                        className="size-4"
                                    />
                                </Button>
                            )}
                        </Form>
                        <Link
                            href={quizzesIndex()}
                            className={buttonClasses('outline', 'md', 'w-full')}
                        >
                            Back to quizzes
                        </Link>
                    </div>
                </Card>

                <h2 className="text-section font-bold">Your answers</h2>

                <ol className="flex flex-col gap-3 sm:gap-4">
                    {questions.map((question) => (
                        <li key={question.position}>
                            <Card className="flex flex-col gap-4 p-card">
                                <div className="flex items-start gap-3">
                                    <span
                                        className={cn(
                                            'flex size-8 shrink-0 items-center justify-center rounded-full',
                                            question.isCorrect
                                                ? 'bg-present-soft text-present'
                                                : 'bg-absent-soft text-absent',
                                        )}
                                    >
                                        <Icon
                                            name={
                                                question.isCorrect
                                                    ? 'check'
                                                    : 'cross'
                                            }
                                            className="size-4"
                                        />
                                        <span className="sr-only">
                                            {question.isCorrect
                                                ? 'Right'
                                                : 'Not right'}
                                        </span>
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <p className="text-[0.8125rem] text-muted-foreground">
                                            Question {question.position} ·{' '}
                                            <span className="tabular-nums">
                                                {question.marksAwarded ?? 0}/
                                                {question.marks}
                                            </span>{' '}
                                            {question.marks === 1
                                                ? 'mark'
                                                : 'marks'}
                                        </p>
                                        <p className="leading-relaxed font-semibold">
                                            <MathText text={question.stem} />
                                        </p>
                                        {question.imageUrl && (
                                            <img
                                                src={question.imageUrl}
                                                alt=""
                                                className="mt-2 max-h-56 self-start rounded-md border object-contain"
                                            />
                                        )}
                                    </div>
                                </div>

                                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                    <div className="rounded-md bg-surface-muted px-3 py-2.5">
                                        <dt className="text-[0.8125rem] text-muted-foreground">
                                            Your answer
                                        </dt>
                                        <dd
                                            className={cn(
                                                'font-semibold',
                                                !question.yourAnswer &&
                                                    'text-muted-foreground italic',
                                            )}
                                        >
                                            {question.yourAnswer ? (
                                                <MathText
                                                    text={question.yourAnswer}
                                                />
                                            ) : (
                                                'No answer'
                                            )}
                                        </dd>
                                    </div>
                                    {!question.isCorrect && (
                                        <div className="rounded-md bg-present-soft px-3 py-2.5">
                                            <dt className="text-[0.8125rem] text-muted-foreground">
                                                Correct answer
                                            </dt>
                                            <dd className="font-semibold">
                                                <MathText
                                                    text={
                                                        question.correctAnswer
                                                    }
                                                />
                                            </dd>
                                        </div>
                                    )}
                                </dl>

                                {question.explanation && (
                                    <div className="flex gap-2.5 border-t pt-3 text-sm leading-relaxed">
                                        <Icon
                                            name="bulb"
                                            className="mt-0.5 size-4 shrink-0 text-accent"
                                        />
                                        <MathText text={question.explanation} />
                                    </div>
                                )}
                            </Card>
                        </li>
                    ))}
                </ol>
            </div>
        </AppLayout>
    );
}

function ScoreRing({ percentage }: { percentage: number }) {
    const radius = 42;
    const circumference = 2 * Math.PI * radius;

    return (
        <div className="relative size-28 shrink-0">
            <svg
                viewBox="0 0 100 100"
                className="size-full -rotate-90"
                aria-hidden="true"
            >
                <circle
                    cx="50"
                    cy="50"
                    r={radius}
                    fill="none"
                    strokeWidth="10"
                    className="stroke-surface-muted"
                />
                <circle
                    cx="50"
                    cy="50"
                    r={radius}
                    fill="none"
                    strokeWidth="10"
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={circumference * (1 - percentage / 100)}
                    className={cn(
                        percentage >= 70
                            ? 'stroke-present'
                            : percentage >= 50
                              ? 'stroke-accent'
                              : 'stroke-primary',
                    )}
                />
            </svg>
            <span className="absolute inset-0 flex items-center justify-center text-2xl font-bold tabular-nums">
                {percentage}%
            </span>
        </div>
    );
}
