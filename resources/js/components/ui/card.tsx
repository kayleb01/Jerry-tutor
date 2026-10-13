import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Card({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'rounded-lg border bg-surface text-foreground shadow-card',
                className,
            )}
            {...props}
        />
    );
}

export function CardHeader({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn('flex flex-col gap-1 p-5 pb-0', className)}
            {...props}
        />
    );
}

export function CardTitle({ className, ...props }: ComponentProps<'h3'>) {
    return (
        <h3
            className={cn('text-lg leading-tight font-semibold', className)}
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
    return <div className={cn('p-5', className)} {...props} />;
}
