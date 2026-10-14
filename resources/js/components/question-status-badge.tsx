import { Badge } from '@/components/ui/badge';
import type { BadgeTone } from '@/components/ui/badge';

export type QuestionStatus = 'draft' | 'in_review' | 'published' | 'retired';

const tones: Record<QuestionStatus, BadgeTone> = {
    draft: 'neutral',
    in_review: 'gold',
    published: 'present',
    retired: 'excused',
};

export default function QuestionStatusBadge({
    status,
    label,
}: {
    status: QuestionStatus;
    label: string;
}) {
    return <Badge tone={tones[status]}>{label}</Badge>;
}
