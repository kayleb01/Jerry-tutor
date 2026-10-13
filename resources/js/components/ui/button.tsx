import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const variants = {
    primary:
        'bg-primary text-primary-foreground shadow-card hover:bg-primary-hover',
    accent: 'bg-accent text-accent-foreground shadow-card hover:bg-accent-hover',
    outline:
        'border border-input bg-surface text-foreground hover:bg-surface-muted',
    ghost: 'text-foreground hover:bg-surface-muted',
    danger: 'bg-danger text-white shadow-card hover:opacity-90',
} as const;

const sizes = {
    sm: 'h-8 gap-1.5 rounded-sm px-3 text-sm',
    md: 'h-10 gap-2 rounded-md px-4 text-sm',
    lg: 'h-12 gap-2 rounded-md px-6 text-base',
} as const;

export type ButtonProps = ComponentProps<'button'> & {
    variant?: keyof typeof variants;
    size?: keyof typeof sizes;
};

export function Button({
    variant = 'primary',
    size = 'md',
    className,
    type = 'button',
    ...props
}: ButtonProps) {
    return (
        <button
            type={type}
            className={cn(
                'inline-flex items-center justify-center font-medium whitespace-nowrap transition-colors disabled:pointer-events-none disabled:opacity-50',
                variants[variant],
                sizes[size],
                className,
            )}
            {...props}
        />
    );
}
