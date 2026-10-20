import { next as takeRegister } from '@/routes/registers';
import { next as nextReport } from '@/routes/reports';
import { Link, usePage } from '@inertiajs/react';
import AppLogoImage from '@/components/app-logo-image';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { cn } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { index as adminAttendanceIndex } from '@/routes/admin/attendance';
import { index as adminClassesIndex } from '@/routes/admin/classes';
import { index as adminLatenessIndex } from '@/routes/admin/lateness';
import { index as adminLessonReportsIndex } from '@/routes/admin/lesson-reports';
import { index as adminQuestionsIndex } from '@/routes/admin/questions';
import { index as adminSubjectsIndex } from '@/routes/admin/subjects';
import { index as adminSubscriptionsIndex } from '@/routes/admin/subscriptions';
import { index as adminTutorApplicationsIndex } from '@/routes/admin/tutor-applications';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { index as billingIndex } from '@/routes/billing';
import { index as tutorClassesIndex } from '@/routes/tutor/classes';
import { index as childrenIndex } from '@/routes/children';
import { index as quizzesIndex } from '@/routes/quizzes';
import type { Role } from '@/types';

type NavItem = {
    label: string;
    icon: IconName;
    href: () => { url: string; method: 'get' };
    /** A count from the shared `badges` prop, shown as a pill when above zero. */
    badge?: 'classReports' | 'lateness' | 'subscriptions' | 'pendingTutors';
    /** Other pages that belong under this item, so it stays highlighted there. */
    alsoActiveOn?: Array<() => { url: string; method: 'get' }>;
};

const navItems: Record<Role, NavItem[]> = {
    admin: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
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
            alsoActiveOn: [adminTutorApplicationsIndex],
        },
        { label: 'Classes', icon: 'classes', href: adminClassesIndex },
        { label: 'Subjects', icon: 'book', href: adminSubjectsIndex },
        {
            label: 'Lateness reports',
            icon: 'alarm',
            href: adminLatenessIndex,
            badge: 'lateness',
        },
        {
            label: 'Class reports',
            icon: 'report',
            href: adminLessonReportsIndex,
            badge: 'classReports',
        },
        {
            label: 'Question bank',
            icon: 'question',
            href: adminQuestionsIndex,
        },
        {
            label: 'Subscriptions',
            icon: 'card',
            href: adminSubscriptionsIndex,
            badge: 'subscriptions',
        },
    ],
    tutor: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
        { label: 'My classes', icon: 'classes', href: tutorClassesIndex },
        { label: 'Take the register', icon: 'list', href: takeRegister },
        { label: 'End-of-class report', icon: 'report', href: nextReport },
    ],
    student: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
        { label: 'Quizzes', icon: 'calculator', href: quizzesIndex },
    ],
    guardian: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
        { label: 'Children', icon: 'children', href: childrenIndex },
        { label: 'Billing', icon: 'card', href: billingIndex },
    ],
};

type AppSidebarProps = {
    collapsed: boolean;
    onNavigate?: () => void;
};

const roleLabels: Record<Role, string> = {
    admin: 'Admin',
    tutor: 'Tutor',
    student: 'Student',
    guardian: 'Parent',
};

const itemClass =
    'relative flex h-11 items-center gap-3 rounded-md px-3 text-sm transition-colors';

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

export default function AppSidebar({ collapsed, onNavigate }: AppSidebarProps) {
    const { auth, badges, billing, pendingTutorCount } = usePage().props;
    const counts = { ...badges, pendingTutors: pendingTutorCount ?? 0 };
    const currentPath = usePage().url.split('?')[0];

    if (!auth.user) {
        return null;
    }

    const isActive = (url: string) =>
        currentPath === url || currentPath.startsWith(`${url}/`);

    return (
        <div className="flex h-full flex-col gap-5 border-r bg-surface px-4 py-5 text-foreground">
            <Link
                href={dashboard()}
                onClick={onNavigate}
                aria-label="Jerry Great Academy dashboard"
                className={cn('px-2', collapsed && 'mx-auto px-0')}
            >
                <AppLogoImage
                    crest={collapsed}
                    className={collapsed ? 'w-9' : 'w-28'}
                />
            </Link>
            {!collapsed && (
                <p className="px-3 text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                    {roleLabels[auth.user.role]}
                </p>
            )}
            <nav aria-label="Main" className="flex flex-1 flex-col gap-1">
                {[
                    ...navItems[auth.user.role],
                    // Students without a parent account pay for themselves.
                    ...(auth.user.role === 'student' && billing?.selfPay
                        ? [
                              {
                                  label: 'Billing',
                                  icon: 'card' as const,
                                  href: billingIndex,
                              },
                          ]
                        : []),
                ].map((item: NavItem) => {
                    const active = [
                        item.href,
                        ...(item.alsoActiveOn ?? []),
                    ].some((href) => isActive(href().url));
                    const count = item.badge ? (counts[item.badge] ?? 0) : 0;

                    return (
                        <Link
                            key={item.label}
                            href={item.href()}
                            onClick={onNavigate}
                            aria-current={active ? 'page' : undefined}
                            title={collapsed ? item.label : undefined}
                            className={cn(
                                itemClass,
                                active
                                    ? 'bg-primary font-semibold text-primary-foreground'
                                    : 'font-medium text-muted-foreground hover:bg-surface-muted hover:text-foreground',
                                collapsed && 'justify-center px-0',
                            )}
                        >
                            <Icon name={item.icon} />
                            <span className={cn(collapsed && 'sr-only')}>
                                {item.label}
                            </span>
                            {count > 0 && (
                                <span
                                    className={cn(
                                        'ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-secondary-80 px-1.5 text-[0.6875rem] font-bold text-secondary-10 tabular-nums',
                                        collapsed &&
                                            'absolute top-1 right-1 ml-0 h-4 min-w-4 px-1 text-[0.625rem]',
                                    )}
                                >
                                    {count > 99 ? '99+' : count}
                                    <span className="sr-only"> to review</span>
                                </span>
                            )}
                        </Link>
                    );
                })}
            </nav>
            <div className="flex flex-col gap-2 border-t pt-4">
                <div
                    className={cn(
                        'flex items-center gap-3 px-2',
                        collapsed && 'justify-center px-0',
                    )}
                    title={collapsed ? auth.user.name : undefined}
                >
                    <span
                        aria-hidden="true"
                        className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-90 text-[0.8125rem] font-semibold text-primary-10 dark:bg-primary-20 dark:text-primary-90"
                    >
                        {initials(auth.user.name)}
                    </span>
                    <div className={cn('min-w-0', collapsed && 'sr-only')}>
                        <p className="truncate text-sm font-semibold">
                            {auth.user.name}
                        </p>
                        <p className="truncate text-[0.8125rem] text-muted-foreground">
                            {roleLabels[auth.user.role]}
                        </p>
                    </div>
                </div>
                <Link
                    href={logout()}
                    as="button"
                    title={collapsed ? 'Log out' : undefined}
                    className={cn(
                        itemClass,
                        'font-medium text-muted-foreground hover:bg-surface-muted hover:text-foreground',
                        collapsed && 'justify-center px-0',
                    )}
                >
                    <Icon name="logout" />
                    <span className={cn(collapsed && 'sr-only')}>Log out</span>
                </Link>
            </div>
        </div>
    );
}
