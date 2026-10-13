import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Select({ className, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'h-11 w-full rounded-sm border border-input bg-surface px-3.5 text-[15px] text-foreground disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            {...props}
        />
    );
}
