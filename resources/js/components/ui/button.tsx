import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const buttonVariants = {
    primary: 'bg-primary text-primary-foreground hover:bg-primary-hover',
    accent: 'bg-accent text-accent-foreground hover:bg-accent-hover',
    secondary:
        'bg-surface-muted text-muted-foreground hover:bg-neutral-90 hover:text-foreground dark:hover:bg-neutral-30',
    outline:
        'border border-input bg-transparent text-muted-foreground hover:bg-surface-muted hover:text-foreground',
    ghost: 'text-foreground hover:bg-surface-muted',
    danger: 'bg-danger text-white hover:opacity-90 dark:text-neutral-10',
} as const;

export const buttonSizes = {
    sm: 'h-8 gap-1.5 px-3 text-sm',
    md: 'h-10 gap-2 px-4 text-sm',
    lg: 'h-12 gap-2 px-6 text-base',
} as const;

export type ButtonVariant = keyof typeof buttonVariants;
export type ButtonSize = keyof typeof buttonSizes;

/** Classes for anything that should look like a button, such as an Inertia `Link`. */
export function buttonClasses(
    variant: ButtonVariant = 'primary',
    size: ButtonSize = 'md',
    className?: string,
) {
    return cn(
        'inline-flex items-center justify-center rounded-sm font-semibold whitespace-nowrap transition-colors disabled:pointer-events-none disabled:opacity-40',
        buttonVariants[variant],
        buttonSizes[size],
        className,
    );
}

export type ButtonProps = ComponentProps<'button'> & {
    variant?: ButtonVariant;
    size?: ButtonSize;
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
            className={buttonClasses(variant, size, className)}
            {...props}
        />
    );
}
