import { Form, Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { show as showAttempt } from '@/routes/quiz-attempts';
import { store as startQuiz } from '@/routes/quizzes/attempts';

type QuizCard = {
    slug: string;
    title: string;
    strand: 'number' | 'algebra' | 'statistics' | 'geometry' | null;
    available: number;
    lastScore: string | null;
    bestPercentage: number | null;
    inProgress: boolean;
};

type Section = {
    key: string;
    title: string;
    locked: boolean;
    progress: { done: number; needed: number } | null;
    quizzes: QuizCard[];
};

type QuizzesProps = {
    yearGroup: string | null;
    sections: Section[];
    recent: { id: number; title: string; score: string; date: string }[];
};

const strandDots: Record<NonNullable<QuizCard['strand']>, string> = {
    number: 'bg-strand-number',
    algebra: 'bg-strand-algebra',
    statistics: 'bg-strand-statistics',
    geometry: 'bg-strand-geometry',
};

export default function Quizzes({ yearGroup, sections, recent }: QuizzesProps) {
    return (
        <AppLayout title="Quizzes" subtitle={yearGroup ?? 'Practice'}>
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Ten questions at a time, no timer. Take your time, and read the
                explanations at the end.
            </p>

            {sections.length === 0 && (
                <Card className="p-card text-sm text-muted-foreground">
                    Your year group isn’t set yet. Ask the academy to add it,
                    and your quizzes will appear here.
                </Card>
            )}

            {sections.map((section) => (
                <section
                    key={section.key}
                    aria-labelledby={`section-${section.key}`}
                >
                    <h2
                        id={`section-${section.key}`}
                        className="mb-3 flex items-center gap-2 text-section font-bold"
                    >
                        {section.title}
                        {section.locked && (
                            <Icon
                                name="lock"
                                className="size-5 text-muted-foreground"
                            />
                        )}
                    </h2>
                    {section.locked && section.progress ? (
                        <Card className="flex flex-wrap items-center gap-4 p-card">
                            <p className="min-w-0 flex-1 text-sm">
                                Score 80% or more in {section.progress.needed}{' '}
                                of your year’s quizzes to unlock harder
                                Challenge questions.
                            </p>
                            <div
                                className="flex items-center gap-1.5"
                                role="img"
                                aria-label={`${section.progress.done} of ${section.progress.needed} done`}
                            >
                                {Array.from(
                                    { length: section.progress.needed },
                                    (_, index) => (
                                        <span
                                            key={index}
                                            className={cn(
                                                'size-3 rounded-full',
                                                index < section.progress!.done
                                                    ? 'bg-secondary-60'
                                                    : 'bg-surface-muted ring-1 ring-border',
                                            )}
                                        />
                                    ),
                                )}
                                <span className="ml-1 text-[0.8125rem] text-muted-foreground tabular-nums">
                                    {section.progress.done} of{' '}
                                    {section.progress.needed}
                                </span>
                            </div>
                        </Card>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3">
                            {section.quizzes.map((quiz) => (
                                <QuizTile key={quiz.slug} quiz={quiz} />
                            ))}
                        </div>
                    )}
                </section>
            ))}

            {recent.length > 0 && (
                <section aria-labelledby="recent-heading">
                    <h2
                        id="recent-heading"
                        className="mb-3 text-section font-bold"
                    >
                        Your recent quizzes
                    </h2>
                    <Card className="divide-y p-0">
                        {recent.map((attempt) => (
                            <Link
                                key={attempt.id}
                                href={showAttempt(attempt.id)}
                                className="flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-surface-muted sm:px-5"
                            >
                                <span className="min-w-0 truncate font-medium">
                                    {attempt.title}
                                </span>
                                <span className="flex shrink-0 items-center gap-3 text-muted-foreground">
                                    <span className="font-semibold text-foreground tabular-nums">
                                        {attempt.score}
                                    </span>
                                    {attempt.date}
                                    <Icon
                                        name="arrow-right"
                                        className="size-4"
                                    />
                                </span>
                            </Link>
                        ))}
                    </Card>
                </section>
            )}
        </AppLayout>
    );
}

function QuizTile({ quiz }: { quiz: QuizCard }) {
    const ready = quiz.available > 0;

    return (
        <Card className="flex flex-col gap-4 p-card">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="flex items-center gap-2 font-bold">
                        {quiz.strand && (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    strandDots[quiz.strand],
                                )}
                            />
                        )}
                        {quiz.title}
                    </h3>
                    <p className="text-[0.8125rem] text-muted-foreground">
                        {ready
                            ? `${quiz.available} ${quiz.available === 1 ? 'question' : 'questions'} to practise`
                            : 'No questions yet'}
                    </p>
                </div>
                {quiz.lastScore && (
                    <span className="shrink-0 rounded-full bg-secondary-95 px-2.5 py-1 text-[0.8125rem] font-semibold text-secondary-10 tabular-nums dark:bg-secondary-20 dark:text-secondary-90">
                        {quiz.lastScore}
                    </span>
                )}
            </div>
            {quiz.bestPercentage !== null && (
                <div className="flex items-center gap-2">
                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-surface-muted">
                        <div
                            className="h-full rounded-full bg-primary"
                            style={{ width: `${quiz.bestPercentage}%` }}
                        />
                    </div>
                    <span className="text-[0.8125rem] text-muted-foreground tabular-nums">
                        Best {quiz.bestPercentage}%
                    </span>
                </div>
            )}
            <Form {...startQuiz.form(quiz.slug)} className="mt-auto">
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant={quiz.inProgress ? 'accent' : 'primary'}
                        className="w-full"
                        disabled={!ready || processing}
                    >
                        {quiz.inProgress
                            ? 'Continue'
                            : quiz.lastScore
                              ? 'Try again'
                              : 'Start'}
                        <Icon name="arrow-right" className="size-4" />
                    </Button>
                )}
            </Form>
        </Card>
    );
}
