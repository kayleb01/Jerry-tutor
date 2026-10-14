import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

const tones = {
    royal: 'bg-primary-95 text-primary-30 dark:bg-primary-20 dark:text-primary-90',
    gold: 'bg-secondary-95 text-secondary-40 dark:bg-secondary-20 dark:text-secondary-90',
    present:
        'bg-primary-90 text-primary-10 dark:bg-primary-30 dark:text-primary-95',
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
        <Card className="flex flex-col gap-1 p-card sm:gap-1.5">
            <div className="flex items-center justify-between gap-3">
                <p className="text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs">
                    {label}
                </p>
                <span
                    className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-md sm:size-10',
                        tones[tone],
                    )}
                >
                    <Icon name={icon} />
                </span>
            </div>
            <p className="text-stat tabular-nums">{value}</p>
            {hint && (
                <p className="text-xs text-muted-foreground sm:text-[13px]">
                    {hint}
                </p>
            )}
        </Card>
    );
}
