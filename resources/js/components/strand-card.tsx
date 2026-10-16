import { Form, Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { index as quizzesIndex } from '@/routes/quizzes';
import { store as startQuiz } from '@/routes/quizzes/attempts';
import type { Strand } from '@/types';

export const strandAccents: Record<Strand['key'], string> = {
    number: 'bg-strand-number',
    algebra: 'bg-strand-algebra',
    statistics: 'bg-strand-statistics',
    geometry: 'bg-strand-geometry',
};

type StrandCardProps = {
    strand: Strand;
    yearGroup: string | null;
    quizAccess: boolean;
};

export default function StrandCard({
    strand,
    yearGroup,
    quizAccess,
}: StrandCardProps) {
    return (
        <Card className="flex flex-col">
            <div className="flex items-start justify-between gap-3 border-b px-4 py-3.5 sm:px-5 sm:py-4">
                <div>
                    <h3 className="flex flex-wrap items-center gap-2 text-lg font-bold">
                        <span
                            className={cn(
                                'size-2.5 rounded-full',
                                strandAccents[strand.key],
                            )}
                            aria-hidden="true"
                        />
                        {strand.name}
                        {yearGroup && <Badge tone="brand">{yearGroup}</Badge>}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">Maths</p>
                </div>
            </div>
            <div className="flex flex-1 flex-col gap-3 px-4 py-3.5 sm:gap-4 sm:px-5 sm:py-4">
                <p className="text-sm leading-relaxed">{strand.summary}</p>
                <div className="mt-auto flex items-center gap-3">
                    <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                        {strand.lastScore ? (
                            <>
                                Last quiz{' '}
                                <span className="font-semibold text-foreground tabular-nums">
                                    {strand.lastScore}
                                </span>
                            </>
                        ) : (
                            'No quizzes yet'
                        )}
                    </p>
                    {quizAccess && strand.quizSlug ? (
                        <Form {...startQuiz.form(strand.quizSlug)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    <Icon name="trophy" className="size-4" />
                                    {strand.lastScore
                                        ? 'Practise again'
                                        : 'Start quiz'}
                                </Button>
                            )}
                        </Form>
                    ) : (
                        <Link
                            href={quizzesIndex()}
                            className={buttonClasses('outline')}
                        >
                            <Icon name="trophy" className="size-4" />
                            Quizzes
                        </Link>
                    )}
                </div>
            </div>
        </Card>
    );
}
