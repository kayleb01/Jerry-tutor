import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Select({ className, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'h-10 w-full rounded-md border border-input bg-surface px-3 text-sm text-foreground disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            {...props}
        />
    );
}
