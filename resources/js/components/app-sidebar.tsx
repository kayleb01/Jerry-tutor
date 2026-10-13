import { Link, usePage } from '@inertiajs/react';
import AppLogoImage from '@/components/app-logo-image';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { cn } from '@/lib/utils';
import { dashboard, logout } from '@/routes';
import { index as adminClassesIndex } from '@/routes/admin/classes';
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
        { label: 'Reports', icon: 'book', href: adminLessonReportsIndex },
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

const itemClass =
    'flex h-11 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors';

export default function AppSidebar({ collapsed, onNavigate }: AppSidebarProps) {
    const { auth } = usePage().props;
    const currentPath = usePage().url.split('?')[0];

    if (!auth.user) {
        return null;
    }

    const isActive = (url: string) =>
        currentPath === url || currentPath.startsWith(`${url}/`);

    return (
        <div className="flex h-full flex-col gap-6 bg-royal-950 px-3 py-5 text-royal-100 [&_*:focus-visible]:outline-gold-400">
            <Link
                href={dashboard()}
                onClick={onNavigate}
                aria-label="Jerry Great Academy dashboard"
                className={cn('px-1', collapsed && 'mx-auto px-0')}
            >
                <AppLogoImage
                    onDark
                    crest={collapsed}
                    className={collapsed ? 'w-12 px-2 py-1.5' : 'w-48'}
                />
            </Link>
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
                                    ? 'bg-royal-700 text-white shadow-raised'
                                    : 'text-royal-200 hover:bg-royal-900 hover:text-white',
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
            <Link
                href={logout()}
                as="button"
                title={collapsed ? 'Log out' : undefined}
                className={cn(
                    itemClass,
                    'text-royal-200 hover:bg-royal-900 hover:text-white',
                    collapsed && 'justify-center px-0',
                )}
            >
                <Icon name="logout" />
                <span className={cn(collapsed && 'sr-only')}>Log out</span>
            </Link>
        </div>
    );
}
