import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import AppSidebar from '@/components/app-sidebar';
import Icon from '@/components/icon';
import Toast from '@/components/toast';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { Role } from '@/types';

const roleLabels: Record<Role, string> = {
    admin: 'Admin',
    tutor: 'Tutor',
    student: 'Student',
    guardian: 'Parent',
};

const COLLAPSE_KEY = 'jga.sidebar.collapsed';

function readCollapsed(): boolean {
    try {
        return window.localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
        return false;
    }
}

type AppLayoutProps = {
    title: string;
    subtitle?: string;
    badge?: string;
    actions?: ReactNode;
    children: ReactNode;
};

export default function AppLayout({
    title,
    subtitle,
    badge,
    actions,
    children,
}: AppLayoutProps) {
    const { auth } = usePage().props;
    // Starts expanded on the server and the first client render (SSR), then applies the saved preference.
    const [collapsed, setCollapsed] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const menuButton = useRef<HTMLButtonElement>(null);
    const drawerPanel = useRef<HTMLDivElement>(null);
    const wasOpen = useRef(false);

    useEffect(() => {
        if (readCollapsed()) {
            setCollapsed(true);
        }
    }, []);

    useEffect(() => {
        if (!drawerOpen) {
            if (wasOpen.current) {
                menuButton.current?.focus();
            }
            wasOpen.current = false;

            return;
        }

        wasOpen.current = true;

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setDrawerOpen(false);

                return;
            }

            if (event.key !== 'Tab' || !drawerPanel.current) {
                return;
            }

            const focusable = drawerPanel.current.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled])',
            );
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [drawerOpen]);

    const toggleCollapsed = () => {
        const next = !collapsed;
        setCollapsed(next);

        try {
            window.localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
        } catch {
            // Storage can be unavailable (private mode); the toggle still works for this visit.
        }
    };

    return (
        <div className="min-h-screen bg-background lg:flex">
            <Head title={title} />
            <aside
                className={cn(
                    'sticky top-0 hidden h-screen shrink-0 transition-[width] lg:block',
                    collapsed ? 'w-20' : 'w-64',
                )}
            >
                <AppSidebar collapsed={collapsed} />
            </aside>

            <div className="flex items-center justify-between gap-3 bg-royal-950 px-4 py-3 lg:hidden [&_*:focus-visible]:outline-gold-400">
                <span className="font-display text-lg font-bold tracking-wide text-white uppercase">
                    Jerry Great Academy
                </span>
                <button
                    ref={menuButton}
                    type="button"
                    onClick={() => setDrawerOpen(true)}
                    aria-label="Open menu"
                    aria-expanded={drawerOpen}
                    className="rounded-md p-2 text-royal-100 hover:bg-royal-900"
                >
                    <Icon name="menu" />
                </button>
            </div>
            {drawerOpen && (
                <div
                    className="fixed inset-0 z-40 lg:hidden"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Menu"
                >
                    <button
                        type="button"
                        aria-label="Close menu"
                        tabIndex={-1}
                        className="absolute inset-0 bg-royal-950/60"
                        onClick={() => setDrawerOpen(false)}
                    />
                    <div
                        ref={drawerPanel}
                        className="relative h-full w-72 max-w-[85vw] [&_*:focus-visible]:outline-gold-400"
                    >
                        <AppSidebar
                            collapsed={false}
                            onNavigate={() => setDrawerOpen(false)}
                        />
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(false)}
                            aria-label="Close menu"
                            autoFocus
                            className="absolute top-4 right-3 rounded-md p-2 text-royal-100 hover:bg-royal-900"
                        >
                            <Icon name="close" />
                        </button>
                    </div>
                </div>
            )}

            <div className="min-w-0 flex-1">
                <header className="mx-auto flex max-w-7xl flex-wrap items-start justify-between gap-4 px-4 pt-6 sm:px-6 lg:px-8 lg:pt-8">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={toggleCollapsed}
                            aria-label={
                                collapsed
                                    ? 'Expand sidebar'
                                    : 'Collapse sidebar'
                            }
                            aria-pressed={collapsed}
                            className="hidden rounded-md p-2 text-muted-foreground hover:bg-surface-muted lg:inline-flex"
                        >
                            <Icon name="panel" />
                        </button>
                        <h1 className="font-display text-3xl font-bold tracking-wide uppercase sm:text-4xl">
                            {title}
                        </h1>
                    </div>
                    {auth.user && (
                        <div className="sm:text-right">
                            <p className="flex items-center gap-2 font-semibold sm:justify-end">
                                {auth.user.name}
                                <Badge tone="brand">
                                    {badge ?? roleLabels[auth.user.role]}
                                </Badge>
                            </p>
                            {subtitle && (
                                <p className="text-sm text-muted-foreground">
                                    {subtitle}
                                </p>
                            )}
                        </div>
                    )}
                </header>
                <main className="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8">
                    <Toast />
                    {actions && (
                        <div className="flex flex-wrap gap-3">{actions}</div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
