import { cn } from '@/lib/utils';

/** Up to two initials, ignoring titles like "Mrs" or "Dr". */
export function initials(name: string): string {
    return name
        .replace(/^(Mr|Mrs|Ms|Miss|Dr)\.?\s+/i, '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

type PersonAvatarProps = {
    name: string;
    role: 'tutor' | 'student';
    className?: string;
};

/** Initials in a circle: brand for tutors, gold for students. */
export default function PersonAvatar({
    name,
    role,
    className,
}: PersonAvatarProps) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-full text-[0.8125rem] font-semibold',
                role === 'tutor'
                    ? 'bg-brand text-brand-foreground'
                    : 'bg-secondary-90 text-secondary-10 dark:bg-secondary-20 dark:text-secondary-90',
                className,
            )}
        >
            {initials(name)}
        </span>
    );
}
