import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const tones = {
    neutral: 'bg-surface-muted text-muted-foreground',
    brand: 'bg-royal-100 text-royal-800 dark:bg-royal-800/40 dark:text-royal-200',
    gold: 'bg-gold-100 text-gold-800 dark:bg-gold-500/15 dark:text-gold-300',
    present: 'bg-present-soft text-present',
    late: 'bg-late-soft text-late',
    absent: 'bg-absent-soft text-absent',
    excused: 'bg-excused-soft text-excused',
    easy: 'bg-easy-soft text-easy',
    intermediate: 'bg-intermediate-soft text-intermediate',
    hard: 'bg-hard-soft text-hard',
    challenging: 'bg-challenging-soft text-challenging',
} as const;

export type BadgeTone = keyof typeof tones;

export type BadgeProps = ComponentProps<'span'> & {
    tone?: BadgeTone;
};

export function Badge({ tone = 'neutral', className, ...props }: BadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
                tones[tone],
                className,
            )}
            {...props}
        />
    );
}
