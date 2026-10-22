import { Badge } from '@/components/ui/badge';
import type { BadgeTone } from '@/components/ui/badge';

export type QuizAccess = {
    state: 'covered' | 'pending' | 'trial' | 'none';
    /** Still covered during the grace period, but the last renewal failed. */
    paymentFailed: boolean;
};

const tones: Record<QuizAccess['state'], BadgeTone> = {
    covered: 'present',
    pending: 'gold',
    trial: 'brand',
    none: 'neutral',
};

const labels: Record<QuizAccess['state'], string> = {
    covered: 'Quiz access',
    pending: 'Waiting',
    trial: 'Free trial',
    none: 'No access',
};

/** A student's quiz access, as shown to whoever pays for it. */
export default function QuizAccessBadge({ state, paymentFailed }: QuizAccess) {
    return paymentFailed ? (
        <Badge tone="absent">Payment failed</Badge>
    ) : (
        <Badge tone={tones[state]}>{labels[state]}</Badge>
    );
}
