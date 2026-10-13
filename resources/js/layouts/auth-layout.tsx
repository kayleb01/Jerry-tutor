import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogoImage from '@/components/app-logo-image';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { home } from '@/routes';

type AuthLayoutProps = {
    title: string;
    description?: string;
    children: ReactNode;
};

const highlights: { icon: IconName; label: string }[] = [
    { icon: 'calculator', label: 'Maths quizzes for Years 3–9' },
    { icon: 'check', label: 'Attendance and punctuality for every class' },
    { icon: 'school', label: '11+, SATs and GCSE preparation' },
];

export default function AuthLayout({
    title,
    description,
    children,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-screen flex-wrap bg-surface">
            <aside className="relative hidden flex-[1_1_480px] flex-col justify-between gap-10 overflow-hidden bg-brand p-14 text-brand-foreground lg:flex">
                <span
                    aria-hidden="true"
                    className="absolute -top-20 -right-20 size-72 rounded-bl-full bg-secondary-key"
                />
                <span
                    aria-hidden="true"
                    className="absolute right-32 -bottom-16 size-44 rounded-tl-full bg-tertiary-30"
                />
                <p className="relative text-sm font-semibold tracking-[0.08em] text-secondary-80 uppercase">
                    Jerry Great Academy
                </p>
                <div className="relative flex max-w-md flex-col gap-5">
                    <p className="text-[44px] leading-[52px] font-extrabold tracking-[-0.01em]">
                        Excellence in maths, on time every time
                    </p>
                    <p className="text-[17px] leading-relaxed text-primary-90">
                        Pick up your quizzes, take the register or check on your
                        child’s progress — all from one account.
                    </p>
                    <ul className="mt-2 flex flex-col gap-3 text-[15px]">
                        {highlights.map((item) => (
                            <li
                                key={item.label}
                                className="flex items-center gap-3"
                            >
                                <Icon
                                    name={item.icon}
                                    className="text-secondary-80"
                                />
                                {item.label}
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="relative text-[13px] text-primary-80">
                    Need help signing in? Contact the academy office.
                </p>
            </aside>

            <main className="flex flex-[1_1_480px] items-center justify-center px-6 py-12">
                <div className="flex w-full max-w-[420px] flex-col gap-7">
                    <Link
                        href={home()}
                        aria-label="Jerry Great Academy home"
                        className="self-start"
                    >
                        <AppLogoImage className="w-44" />
                    </Link>
                    <div className="flex flex-col gap-2">
                        <h1 className="text-[30px] leading-[38px] font-bold">
                            {title}
                        </h1>
                        {description && (
                            <p className="text-[15px] leading-relaxed text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {children}
                </div>
            </main>
        </div>
    );
}
