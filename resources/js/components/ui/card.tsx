import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Card({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'min-w-0 rounded-lg border bg-surface text-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function CardHeader({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn('p-card flex flex-col gap-1 pb-0 sm:pb-0', className)}
            {...props}
        />
    );
}

export function CardTitle({ className, ...props }: ComponentProps<'h3'>) {
    return (
        <h3
            className={cn('text-base leading-snug font-bold sm:text-lg', className)}
            {...props}
        />
    );
}

export function CardDescription({ className, ...props }: ComponentProps<'p'>) {
    return (
        <p
            className={cn('text-sm text-muted-foreground', className)}
            {...props}
        />
    );
}

export function CardContent({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('p-card', className)} {...props} />;
}
