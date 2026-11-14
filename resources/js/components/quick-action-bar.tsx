import { Link, usePage } from '@inertiajs/react';
import { isActivePath } from '@/components/app-sidebar';
import type { NavBadge } from '@/components/app-sidebar';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as adminAttendanceIndex } from '@/routes/admin/attendance';
import { index as adminLessonReportsIndex } from '@/routes/admin/lesson-reports';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { index as billingIndex } from '@/routes/billing';
import { show as checkInShow } from '@/routes/check-in';
import { index as childrenIndex } from '@/routes/children';
import { index as quizzesIndex } from '@/routes/quizzes';
import { next as takeRegister } from '@/routes/registers';
import { next as nextReport } from '@/routes/reports';
import { index as tutorClassesIndex } from '@/routes/tutor/classes';
import type { Role } from '@/types';

type QuickAction = {
    label: string;
    icon: IconName;
    href: () => { url: string; method: 'get' };
    badge?: NavBadge;
};

const home: QuickAction = { label: 'Home', icon: 'dashboard', href: dashboard };

/** The everyday jobs for each role, one tap away on phones. */
function actionsFor(role: Role, quizAccess: boolean): QuickAction[] {
    switch (role) {
        case 'tutor':
            return [
                home,
                { label: 'Students', icon: 'classes', href: tutorClassesIndex },
                { label: 'Attendance', icon: 'list', href: takeRegister },
                { label: 'Report', icon: 'report', href: nextReport },
            ];
        case 'student':
            return [
                home,
                { label: 'Check in', icon: 'clock', href: checkInShow },
                ...(quizAccess
                    ? [
                          {
                              label: 'Tests',
                              icon: 'calculator' as const,
                              href: quizzesIndex,
                          },
                      ]
                    : []),
            ];
        case 'guardian':
            return [
                home,
                { label: 'Children', icon: 'children', href: childrenIndex },
                { label: 'Billing', icon: 'card', href: billingIndex },
            ];
        case 'admin':
            return [
                home,
                {
                    label: 'Attendance',
                    icon: 'check',
                    href: adminAttendanceIndex,
                },
                {
                    label: 'Users',
                    icon: 'users',
                    href: adminUsersIndex,
                    badge: 'pendingTutors',
                },
                {
                    label: 'Class reports',
                    icon: 'report',
                    href: adminLessonReportsIndex,
                    badge: 'classReports',
                },
            ];
    }
}

const itemClass =
    'relative flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[0.6875rem] leading-tight transition-colors';

type QuickActionBarProps = {
    /** Opens the full menu. */
    onMore: () => void;
};

/** A bar fixed to the bottom of the screen on phones and tablets, with the role's everyday jobs. */
export default function QuickActionBar({ onMore }: QuickActionBarProps) {
    const { auth, badges, pendingTutorCount, quizAccess } = usePage().props;
    const currentPath = usePage().url.split('?')[0];

    if (!auth.user) {
        return null;
    }

    const counts = { ...badges, pendingTutors: pendingTutorCount ?? 0 };
    const actions = actionsFor(auth.user.role, quizAccess === true);

    return (
        <nav
            aria-label="Quick actions"
            className="fixed inset-x-0 bottom-0 z-30 border-t bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden"
        >
            <ul
                className="grid"
                style={{
                    gridTemplateColumns: `repeat(${actions.length + 1}, minmax(0, 1fr))`,
                }}
            >
                {actions.map((action) => {
                    const url = action.href().url;
                    const active =
                        url === dashboard().url
                            ? currentPath === url
                            : isActivePath(currentPath, url);
                    const count = action.badge
                        ? (counts[action.badge] ?? 0)
                        : 0;

                    return (
                        <li key={action.label}>
                            <Link
                                href={action.href()}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    itemClass,
                                    active
                                        ? 'font-semibold text-primary'
                                        : 'font-medium text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <span className="relative">
                                    <Icon name={action.icon} />
                                    {count > 0 && (
                                        <span className="absolute -top-1.5 -right-2.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-secondary-80 px-1 text-[0.625rem] font-bold text-secondary-10 tabular-nums">
                                            {count > 99 ? '99+' : count}
                                            <span className="sr-only">
                                                {' '}
                                                to review
                                            </span>
                                        </span>
                                    )}
                                </span>
                                <span className="truncate">{action.label}</span>
                            </Link>
                        </li>
                    );
                })}
                <li>
                    <button
                        type="button"
                        onClick={onMore}
                        className={cn(
                            itemClass,
                            'w-full font-medium text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon name="menu" />
                        <span>More</span>
                    </button>
                </li>
            </ul>
        </nav>
    );
}
