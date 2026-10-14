import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type IconName =
    | 'dashboard'
    | 'users'
    | 'classes'
    | 'children'
    | 'today'
    | 'logout'
    | 'menu'
    | 'close'
    | 'panel'
    | 'chart'
    | 'check'
    | 'clock'
    | 'trophy'
    | 'list'
    | 'columns'
    | 'book'
    | 'alarm'
    | 'cross'
    | 'info'
    | 'search'
    | 'arrow-right'
    | 'arrow-left'
    | 'school'
    | 'calculator'
    | 'report'
    | 'flag'
    | 'shield'
    | 'sparkle'
    | 'plus'
    | 'filter'
    | 'question'
    | 'download';

const paths: Record<IconName, ReactNode> = {
    dashboard: (
        <>
            <rect x="3" y="3" width="7" height="7" rx="1.5" />
            <rect x="14" y="3" width="7" height="7" rx="1.5" />
            <rect x="3" y="14" width="7" height="7" rx="1.5" />
            <rect x="14" y="14" width="7" height="7" rx="1.5" />
        </>
    ),
    users: (
        <>
            <circle cx="9" cy="8" r="3.5" />
            <path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
            <path d="M16 4.5a3.5 3.5 0 0 1 0 7M18.5 20a6.5 6.5 0 0 0-2.5-5.1" />
        </>
    ),
    classes: (
        <>
            <rect x="3" y="4.5" width="18" height="16" rx="2" />
            <path d="M3 9.5h18M8 2.5v4M16 2.5v4" />
        </>
    ),
    children: (
        <>
            <circle cx="8" cy="7" r="3" />
            <circle cx="17" cy="9" r="2.5" />
            <path d="M2.5 20a5.5 5.5 0 0 1 11 0M13.5 20a4 4 0 0 1 8 0" />
        </>
    ),
    today: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    clock: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    logout: (
        <>
            <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3" />
            <path d="M10 16l-4-4 4-4M6 12h10" />
        </>
    ),
    menu: <path d="M4 6h16M4 12h16M4 18h16" />,
    close: <path d="M6 6l12 12M18 6L6 18" />,
    panel: (
        <>
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="M9 4v16" />
        </>
    ),
    chart: <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />,
    check: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M8 12.5l2.5 2.5L16 9.5" />
        </>
    ),
    trophy: (
        <>
            <path d="M8 4h8v5a4 4 0 0 1-8 0V4Z" />
            <path d="M8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8.5 20h7M10 17h4" />
        </>
    ),
    list: <path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01" />,
    columns: (
        <>
            <rect x="3" y="4" width="18" height="16" rx="2" />
            <path d="M9 4v16M15 4v16" />
        </>
    ),
    book: (
        <>
            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15Z" />
            <path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5" />
        </>
    ),
    alarm: (
        <>
            <circle cx="12" cy="13" r="7.5" />
            <path d="M12 9.5V13l2.5 1.5M4 4.5 6.5 2.5M20 4.5l-2.5-2" />
        </>
    ),
    cross: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M9 9l6 6M15 9l-6 6" />
        </>
    ),
    info: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5M12 7.75h.01" />
        </>
    ),
    search: (
        <>
            <circle cx="11" cy="11" r="6.5" />
            <path d="M20 20l-4.2-4.2" />
        </>
    ),
    'arrow-right': <path d="M5 12h14M13 6l6 6-6 6" />,
    'arrow-left': <path d="M19 12H5M11 6l-6 6 6 6" />,
    school: (
        <>
            <path d="M2.5 9 12 4.5 21.5 9 12 13.5 2.5 9Z" />
            <path d="M6.5 11v4.5c1.5 1.5 3.5 2.5 5.5 2.5s4-1 5.5-2.5V11M21.5 9v5" />
        </>
    ),
    calculator: (
        <>
            <rect x="5" y="2.5" width="14" height="19" rx="2" />
            <path d="M8.5 6.5h7M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5h.01M8.5 18h.01M12 18h.01M15.5 18h.01" />
        </>
    ),
    report: (
        <>
            <path d="M6 2.5h8.5L19 7v14.5H6V2.5Z" />
            <path d="M14 2.5V7h5M9 12h7M9 16h5" />
        </>
    ),
    flag: <path d="M5 21V4M5 4h11l-2 4 2 4H5" />,
    shield: (
        <>
            <path d="M12 2.5 20 5.5v6c0 4.5-3.4 8.4-8 10-4.6-1.6-8-5.5-8-10v-6l8-3Z" />
            <path d="M8.5 12l2.5 2.5 4.5-5" />
        </>
    ),
    plus: <path d="M12 5v14M5 12h14" />,
    filter: <path d="M4 5h16l-6 7.5V19l-4 1.5v-8L4 5Z" />,
    download: <path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19h14" />,
    question: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01" />
        </>
    ),
    sparkle: (
        <path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18" />
    ),
};

type IconProps = {
    name: IconName;
    className?: string;
};

export default function Icon({ name, className }: IconProps) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={cn('size-5 shrink-0', className)}
        >
            {paths[name]}
        </svg>
    );
}
