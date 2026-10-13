import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

const tones = {
    royal: 'bg-royal-100 text-royal-700 dark:bg-royal-800/50 dark:text-royal-200',
    gold: 'bg-gold-100 text-gold-700 dark:bg-gold-500/15 dark:text-gold-300',
    present: 'bg-present-soft text-present',
} as const;

type StatCardProps = {
    value: string;
    label: string;
    hint?: string;
    icon: IconName;
    tone: keyof typeof tones;
};

export default function StatCard({
    value,
    label,
    hint,
    icon,
    tone,
}: StatCardProps) {
    return (
        <Card className="flex items-center justify-between gap-4 p-6">
            <div className="min-w-0">
                <p className="font-display text-4xl leading-none font-bold tabular-nums">
                    {value}
                </p>
                <p className="mt-2 text-base text-muted-foreground">{label}</p>
                {hint && (
                    <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
                )}
            </div>
            <div
                className={cn(
                    'flex size-16 shrink-0 items-center justify-center rounded-xl',
                    tones[tone],
                )}
            >
                <Icon name={icon} className="size-8" />
            </div>
        </Card>
    );
}
