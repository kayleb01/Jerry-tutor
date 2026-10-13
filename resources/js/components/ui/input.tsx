import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Input({ className, ...props }: ComponentProps<'input'>) {
    return (
        <input
            className={cn(
                'h-11 w-full rounded-sm border border-input bg-surface px-3.5 text-[15px] text-foreground placeholder:text-muted-foreground read-only:bg-surface-muted disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            {...props}
        />
    );
}
