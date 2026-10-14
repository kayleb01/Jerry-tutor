import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge that knows our responsive scale utilities (resources/css/app.css),
 * so e.g. cn('p-card', 'p-0') resolves to 'p-0' and cn('text-body', 'text-xs') to 'text-xs'.
 */
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            p: ['p-card'],
            'font-size': [
                'text-title',
                'text-section',
                'text-stat',
                'text-body',
                'text-lead',
                'text-headline',
                'text-display',
            ],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
