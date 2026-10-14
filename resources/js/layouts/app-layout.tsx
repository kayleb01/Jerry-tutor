import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import AppLogoImage from '@/components/app-logo-image';
import AppSidebar from '@/components/app-sidebar';
import Icon from '@/components/icon';
import Toast from '@/components/toast';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

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

            <div className="flex items-center justify-between gap-3 border-b bg-surface px-4 py-3 lg:hidden">
                <AppLogoImage className="w-28" />
                <button
                    ref={menuButton}
                    type="button"
                    onClick={() => setDrawerOpen(true)}
                    aria-label="Open menu"
                    aria-expanded={drawerOpen}
                    className="rounded-md p-2 text-muted-foreground hover:bg-surface-muted"
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
                        className="absolute inset-0 bg-primary-10/60"
                        onClick={() => setDrawerOpen(false)}
                    />
                    <div
                        ref={drawerPanel}
                        className="relative h-full w-72 max-w-[85vw]"
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
                            className="absolute top-4 right-3 rounded-md p-2 text-muted-foreground hover:bg-surface-muted"
                        >
                            <Icon name="close" />
                        </button>
                    </div>
                </div>
            )}

            <div className="min-w-0 flex-1">
                <header className="mx-auto flex max-w-7xl flex-wrap items-end justify-between gap-4 px-4 pt-4 sm:px-6 sm:pt-6 lg:px-8 lg:pt-7">
                    <div className="flex min-w-0 items-start gap-3">
                        <button
                            type="button"
                            onClick={toggleCollapsed}
                            aria-label={
                                collapsed
                                    ? 'Expand sidebar'
                                    : 'Collapse sidebar'
                            }
                            aria-pressed={collapsed}
                            className="mt-1 hidden rounded-md p-2 text-muted-foreground hover:bg-surface-muted lg:inline-flex"
                        >
                            <Icon name="panel" />
                        </button>
                        <div className="min-w-0">
                            {subtitle && (
                                <p className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                    {subtitle}
                                </p>
                            )}
                            <h1 className="mt-1 flex flex-wrap items-center gap-3 text-title">
                                {title}
                                {auth.user && badge && (
                                    <Badge tone="brand">{badge}</Badge>
                                )}
                            </h1>
                        </div>
                    </div>
                    {actions && (
                        <div className="flex flex-wrap items-center gap-3">
                            {actions}
                        </div>
                    )}
                </header>
                <main className="mx-auto flex max-w-7xl flex-col gap-4 px-4 pt-4 pb-10 sm:gap-5 sm:px-6 sm:pt-6 sm:pb-12 lg:gap-6 lg:px-8">
                    <Toast />
                    {children}
                </main>
            </div>
        </div>
    );
}
