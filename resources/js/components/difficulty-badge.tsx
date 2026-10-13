import { Badge } from '@/components/ui/badge';

export type Difficulty = 'easy' | 'intermediate' | 'hard' | 'challenging';

const labels: Record<Difficulty, string> = {
    easy: 'Easy',
    intermediate: 'Intermediate',
    hard: 'Hard',
    challenging: 'Challenging',
};

type DifficultyBadgeProps = {
    difficulty: Difficulty;
};

export default function DifficultyBadge({ difficulty }: DifficultyBadgeProps) {
    return <Badge tone={difficulty}>{labels[difficulty]}</Badge>;
}
