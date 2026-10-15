import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Select({ className, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                "h-10 w-full appearance-none rounded-md border border-input bg-surface select-chevron pr-10 pl-3.5 text-sm text-foreground transition-[border-color,box-shadow] hover:border-neutral-60 focus:border-ring focus:ring-3 focus:ring-ring/20 focus:outline-none disabled:opacity-50 aria-invalid:border-danger sm:h-11 sm:pr-11 sm:pl-4 sm:text-[0.9375rem]",
                className,
            )}
            {...props}
        />
    );
}
