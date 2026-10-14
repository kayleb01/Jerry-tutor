import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Input({ className, ...props }: ComponentProps<'input'>) {
    return (
        <input
            className={cn(
                'h-10 w-full rounded-md border border-input bg-surface px-3.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground hover:border-neutral-60 focus:border-ring focus:ring-3 focus:ring-ring/20 focus:outline-none read-only:bg-surface-muted disabled:opacity-50 aria-invalid:border-danger sm:h-11 sm:px-4 sm:text-[15px]',
                className,
            )}
            {...props}
        />
    );
}
