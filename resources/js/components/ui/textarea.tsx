import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
    return (
        <textarea
            className={cn(
                'min-h-24 w-full rounded-md border border-input bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground hover:border-neutral-60 focus:border-ring focus:ring-3 focus:ring-ring/20 focus:outline-none disabled:opacity-50 aria-invalid:border-danger sm:px-4 sm:text-[15px]',
                className,
            )}
            {...props}
        />
    );
}
