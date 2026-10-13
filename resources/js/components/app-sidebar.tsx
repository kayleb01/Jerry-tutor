import { Link, usePage } from '@inertiajs/react';
import AppLogoImage from '@/components/app-logo-image';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { cn } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { index as adminClassesIndex } from '@/routes/admin/classes';
import { index as adminLatenessIndex } from '@/routes/admin/lateness';
import { index as adminLessonReportsIndex } from '@/routes/admin/lesson-reports';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { index as childrenIndex } from '@/routes/children';
import type { Role } from '@/types';

type NavItem = {
    label: string;
    icon: IconName;
    href: () => { url: string; method: 'get' };
};

const navItems: Record<Role, NavItem[]> = {
    admin: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
        { label: 'Users', icon: 'users', href: adminUsersIndex },
        { label: 'Classes', icon: 'classes', href: adminClassesIndex },
        { label: 'Lateness', icon: 'alarm', href: adminLatenessIndex },
        {
            label: 'Class reports',
            icon: 'report',
            href: adminLessonReportsIndex,
        },
    ],
    tutor: [{ label: 'Today', icon: 'today', href: dashboard }],
    student: [{ label: 'Dashboard', icon: 'dashboard', href: dashboard }],
    guardian: [
        { label: 'Dashboard', icon: 'dashboard', href: dashboard },
        { label: 'Children', icon: 'children', href: childrenIndex },
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
    'flex h-11 items-center gap-3 rounded-md px-3 text-sm transition-colors';

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
    const { auth } = usePage().props;
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
                    className={collapsed ? 'w-9' : 'w-40'}
                />
            </Link>
            {!collapsed && (
                <p className="px-3 text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                    {roleLabels[auth.user.role]}
                </p>
            )}
            <nav aria-label="Main" className="flex flex-1 flex-col gap-1">
                {navItems[auth.user.role].map((item) => {
                    const active = isActive(item.href().url);

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
                        className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-90 text-[13px] font-semibold text-primary-10 dark:bg-primary-20 dark:text-primary-90"
                    >
                        {initials(auth.user.name)}
                    </span>
                    <div className={cn('min-w-0', collapsed && 'sr-only')}>
                        <p className="truncate text-sm font-semibold">
                            {auth.user.name}
                        </p>
                        <p className="truncate text-[13px] text-muted-foreground">
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
