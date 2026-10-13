import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import Toast from '@/components/toast';
import { Badge } from '@/components/ui/badge';
import { dashboard, logout } from '@/routes';
import { index as childrenIndex } from '@/routes/children';
import type { Role } from '@/types';

const roleLabels: Record<Role, string> = {
    admin: 'Admin',
    tutor: 'Tutor',
    student: 'Student',
    guardian: 'Parent',
};

const navItems: Record<
    Role,
    { label: string; href: () => { url: string; method: 'get' } }[]
> = {
    admin: [{ label: 'Dashboard', href: dashboard }],
    tutor: [{ label: 'Today', href: dashboard }],
    student: [{ label: 'Dashboard', href: dashboard }],
    guardian: [
        { label: 'Dashboard', href: dashboard },
        { label: 'Children', href: childrenIndex },
    ],
};

type AppLayoutProps = {
    title: string;
    children: ReactNode;
};

export default function AppLayout({ title, children }: AppLayoutProps) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-background">
            <Head title={title} />
            <header className="border-b bg-surface">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
                    <Link href={dashboard()} aria-label="Dashboard">
                        <AppLogo className="text-lg" />
                    </Link>
                    {auth.user && (
                        <nav
                            className="mr-auto flex items-center gap-4"
                            aria-label="Main"
                        >
                            {navItems[auth.user.role].map((item) => (
                                <Link
                                    key={item.label}
                                    href={item.href()}
                                    className="text-sm font-medium text-foreground hover:text-primary"
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                    )}
                    {auth.user && (
                        <div className="flex items-center gap-3">
                            <span className="hidden text-sm font-medium sm:inline">
                                {auth.user.name}
                            </span>
                            <Badge tone="brand">
                                {roleLabels[auth.user.role]}
                            </Badge>
                            <Link
                                href={logout()}
                                as="button"
                                className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                            >
                                Log out
                            </Link>
                        </div>
                    )}
                </div>
            </header>
            <main className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8">
                <Toast />
                <h1 className="font-display text-3xl font-bold tracking-wide uppercase">
                    {title}
                </h1>
                {children}
            </main>
        </div>
    );
}
