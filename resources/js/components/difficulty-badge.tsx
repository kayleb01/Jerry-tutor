import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type Difficulty = 'easy' | 'intermediate' | 'hard' | 'challenging';

const levels: Record<Difficulty, { label: string; pips: number }> = {
    easy: { label: 'Easy', pips: 1 },
    intermediate: { label: 'Intermediate', pips: 2 },
    hard: { label: 'Hard', pips: 3 },
    challenging: { label: 'Challenging', pips: 4 },
};

type DifficultyBadgeProps = {
    difficulty: Difficulty;
};

/** Indigo deepens as difficulty rises; bar pips repeat the level without colour. */
export default function DifficultyBadge({ difficulty }: DifficultyBadgeProps) {
    const level = levels[difficulty];

    return (
        <Badge tone={difficulty} className="gap-2">
            <span className="inline-flex gap-0.5" aria-hidden="true">
                {[1, 2, 3, 4].map((pip) => (
                    <i
                        key={pip}
                        className={cn(
                            'h-2.5 w-1 rounded-[1px] bg-current',
                            pip > level.pips && 'opacity-30',
                        )}
                    />
                ))}
            </span>
            {level.label}
        </Badge>
    );
}
