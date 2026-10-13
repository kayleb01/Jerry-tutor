import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const tones = {
    neutral: 'bg-surface-muted text-muted-foreground',
    brand: 'bg-primary-90 text-primary-10 dark:bg-primary-20 dark:text-primary-90',
    gold: 'bg-secondary-90 text-secondary-10 dark:bg-secondary-20 dark:text-secondary-90',
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
                'inline-flex h-6 items-center gap-1 rounded-md px-2 text-xs font-medium tracking-[0.02em] whitespace-nowrap',
                tones[tone],
                className,
            )}
            {...props}
        />
    );
}
