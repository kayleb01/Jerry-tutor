import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { Strand } from '@/types';

const accents: Record<Strand['key'], string> = {
    number: 'bg-strand-number',
    algebra: 'bg-strand-algebra',
    statistics: 'bg-strand-statistics',
    geometry: 'bg-strand-geometry',
};

type StrandCardProps = {
    strand: Strand;
    yearGroup: string | null;
};

export default function StrandCard({ strand, yearGroup }: StrandCardProps) {
    return (
        <Card className="flex flex-col">
            <div className="flex items-start justify-between gap-3 border-b px-6 py-5">
                <div>
                    <h3 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                        <span
                            className={cn(
                                'size-2.5 rounded-full',
                                accents[strand.key],
                            )}
                            aria-hidden="true"
                        />
                        {strand.name}
                        {yearGroup && <Badge tone="brand">{yearGroup}</Badge>}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">Maths</p>
                </div>
            </div>
            <div className="flex flex-1 flex-col gap-4 px-6 py-5">
                <p className="text-sm leading-relaxed">{strand.summary}</p>
                <div className="mt-auto flex items-center gap-3">
                    <div
                        className="relative h-6 flex-1 overflow-hidden rounded-full bg-surface-muted"
                        role="img"
                        aria-label={`${strand.name} quizzes are coming soon`}
                    >
                        <span className="absolute inset-0 flex items-center justify-center text-xs font-medium text-muted-foreground">
                            Quizzes coming soon
                        </span>
                    </div>
                    <span
                        className="flex size-10 items-center justify-center rounded-full bg-gold-100 text-gold-700 shadow-card dark:bg-gold-500/15 dark:text-gold-300"
                        aria-hidden="true"
                    >
                        <Icon name="trophy" />
                    </span>
                </div>
            </div>
        </Card>
    );
}
