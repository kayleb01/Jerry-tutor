import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AppLogoImage from '@/components/app-logo-image';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import DifficultyBadge from '@/components/difficulty-badge';
import type { Difficulty } from '@/components/difficulty-badge';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { buttonClasses } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard, login, register } from '@/routes';

/** Decision D7: the monthly naira amount is still to be confirmed. */
const MONTHLY_PRICE = '₦[AMOUNT]';

const facts = [
    { value: 'Years 3–9', label: 'KS2 and KS3 quizzes' },
    {
        value: '4 topic areas',
        label: 'Number · Algebra · Statistics · Geometry',
    },
    { value: '4 levels', label: 'Easy to Challenging' },
    { value: '2-way reports', label: 'Tutors and students, every class' },
];

const attendancePoints: { icon: IconName; title: string; body: string }[] = [
    {
        icon: 'check',
        title: 'Present or absent',
        body: 'for every student and every scheduled tutor.',
    },
    {
        icon: 'clock',
        title: 'On time or late',
        body: 'with the scheduled start and the actual arrival time.',
    },
    {
        icon: 'report',
        title: 'Lateness reports',
        body: 'created automatically whenever anyone is late — in both directions.',
    },
    {
        icon: 'book',
        title: 'End-of-class reports',
        body: 'with feedback and recommendations from tutor and student.',
    },
];

const topics = ['Number', 'Algebra', 'Statistics', 'Geometry'];

type StageCard = {
    title: string;
    sub: string;
    level: Difficulty;
    body: string;
};

const stages: Record<
    'ks2' | 'ks3' | 'exam',
    { label: string; cards: StageCard[] }
> = {
    ks2: {
        label: 'KS2 · Years 3–6',
        cards: [
            {
                title: 'Year 3',
                sub: 'KS2 · Primary',
                level: 'easy',
                body: 'All KS2 topics at an easy level.',
            },
            {
                title: 'Year 4',
                sub: 'KS2 · Primary',
                level: 'easy',
                body: 'All KS2 topics, building fluency before difficulty rises.',
            },
            {
                title: 'Year 5',
                sub: 'KS2 · Primary',
                level: 'intermediate',
                body: 'All KS2 topics at intermediate difficulty.',
            },
            {
                title: 'Year 6',
                sub: 'KS2 · Primary',
                level: 'hard',
                body: 'All KS2 topics at a hard level, ready for SATs and the 11+.',
            },
        ],
    },
    ks3: {
        label: 'KS3 · Years 7–9',
        cards: [
            {
                title: 'Year 7',
                sub: 'KS3 · Secondary',
                level: 'easy',
                body: 'All KS3 maths topics at an easy level.',
            },
            {
                title: 'Year 8',
                sub: 'KS3 · Secondary',
                level: 'intermediate',
                body: 'All KS3 maths topics at intermediate difficulty.',
            },
            {
                title: 'Year 9',
                sub: 'KS3 · Secondary',
                level: 'hard',
                body: 'All KS3 maths topics at a hard level.',
            },
            {
                title: 'Additional tier',
                sub: 'KS3 · Stretch',
                level: 'challenging',
                body: 'All KS3 maths topics at the challenging level.',
            },
        ],
    },
    exam: {
        label: 'Exam preparation',
        cards: [
            {
                title: '11+',
                sub: 'Entrance exams',
                level: 'hard',
                body: 'Maths practice for 11+ entrance exams.',
            },
            {
                title: 'SATs',
                sub: 'Year 6',
                level: 'hard',
                body: 'Maths practice for the Year 6 SATs papers.',
            },
            {
                title: 'GCSE',
                sub: 'Secondary',
                level: 'challenging',
                body: 'Maths practice for GCSE students.',
            },
        ],
    },
};

const planFeatures = [
    'All quizzes for your child’s year group',
    '11+, SATs and GCSE preparation',
    'Progress by topic and difficulty',
    'Your child’s attendance and class reports',
];

function SectionHeading({
    eyebrow,
    title,
    body,
    centred = false,
}: {
    eyebrow: string;
    title: string;
    body?: string;
    centred?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex max-w-3xl flex-col gap-3',
                centred && 'mx-auto items-center text-center',
            )}
        >
            <p className="text-xs font-semibold tracking-[0.08em] text-secondary-40 uppercase dark:text-secondary-80">
                {eyebrow}
            </p>
            <h2 className="text-headline font-extrabold text-brand dark:text-primary-90">
                {title}
            </h2>
            {body && <p className="text-lead text-muted-foreground">{body}</p>}
        </div>
    );
}

function Tabs<T extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { id: T; label: string }[];
    value: T;
    onChange: (id: T) => void;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className="flex flex-wrap justify-center gap-2"
        >
            {options.map((option) => (
                <button
                    key={option.id}
                    type="button"
                    aria-pressed={option.id === value}
                    onClick={() => onChange(option.id)}
                    className={cn(
                        'h-10 rounded-full border px-4 text-sm font-semibold transition-colors',
                        option.id === value
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-neutral-80 bg-surface text-muted-foreground hover:text-foreground dark:border-neutral-30',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function BrowserFrame({ url, children }: { url: string; children: ReactNode }) {
    return (
        <div className="overflow-hidden rounded-[20px] border bg-surface shadow-raised">
            <div className="flex items-center gap-2 border-b bg-neutral-95 px-4 py-3 dark:bg-neutral-20">
                <span className="size-2.5 rounded-full bg-neutral-80" />
                <span className="size-2.5 rounded-full bg-neutral-80" />
                <span className="size-2.5 rounded-full bg-neutral-80" />
                <span className="ml-3 truncate text-[0.8125rem] text-muted-foreground">
                    {url}
                </span>
            </div>
            {children}
        </div>
    );
}

function MiniStat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border bg-surface p-3">
            <p className="text-[0.625rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                {label}
            </p>
            <p className="text-xl font-bold">{value}</p>
        </div>
    );
}

function MiniBar({ label, value }: { label: string; value: number }) {
    return (
        <div className="grid grid-cols-[88px_1fr_40px] items-center gap-3 text-[0.8125rem]">
            <span>{label}</span>
            <span className="h-1.5 overflow-hidden rounded-full bg-surface-muted">
                <span
                    className="block h-full rounded-full bg-primary"
                    style={{ width: `${value}%` }}
                />
            </span>
            <span className="text-right">{value}%</span>
        </div>
    );
}

const showcaseViews = [
    { id: 'admin', label: 'Academy admin' },
    { id: 'student', label: 'Student' },
    { id: 'parent', label: 'Parent' },
] as const;

type ShowcaseView = (typeof showcaseViews)[number]['id'];

function Showcase() {
    const [view, setView] = useState<ShowcaseView>('admin');

    return (
        <div className="flex flex-col items-center gap-6 sm:gap-8">
            <Tabs
                label="Choose a view"
                options={[...showcaseViews]}
                value={view}
                onChange={setView}
            />
            <div className="w-full max-w-4xl">
                <BrowserFrame url={`jerrygreatacademy · ${view}`}>
                    <div
                        className="flex min-h-[320px] flex-col gap-3 bg-neutral-95 p-4 sm:min-h-[380px] sm:gap-3.5 sm:p-5 dark:bg-background"
                        aria-hidden="true"
                    >
                        {view === 'admin' && (
                            <>
                                <p className="text-lg font-bold">
                                    Lateness reports today
                                </p>
                                <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                                    <MiniStat
                                        label="Classes today"
                                        value="14"
                                    />
                                    <MiniStat label="Attendance" value="94%" />
                                    <MiniStat label="Late today" value="6" />
                                    <MiniStat label="Reports due" value="3" />
                                </div>
                                <div className="flex flex-col gap-2.5 rounded-md border bg-surface p-4 text-[0.8125rem]">
                                    <div className="flex items-center justify-between gap-2">
                                        <span>Tutor · Year 8 Algebra</span>
                                        <AttendanceStatusBadge
                                            status="late"
                                            minutesLate={5}
                                        />
                                    </div>
                                    <div className="flex items-center justify-between gap-2">
                                        <span>Student · Year 6 Fractions</span>
                                        <AttendanceStatusBadge
                                            status="late"
                                            minutesLate={7}
                                        />
                                    </div>
                                    <div className="flex items-center justify-between gap-2">
                                        <span>Student · Year 4 Number</span>
                                        <AttendanceStatusBadge status="present" />
                                    </div>
                                </div>
                            </>
                        )}
                        {view === 'student' && (
                            <>
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                        Year 8 · Algebra · Question 6 of 20
                                    </p>
                                    <DifficultyBadge difficulty="intermediate" />
                                </div>
                                <span className="h-1.5 overflow-hidden rounded-full bg-surface-muted">
                                    <span className="block h-full w-[30%] rounded-full bg-primary" />
                                </span>
                                <div className="flex flex-col gap-3.5 rounded-md border bg-surface p-5">
                                    <p className="text-lg font-semibold">
                                        Solve for x: 3x + 7 = 25
                                    </p>
                                    <div className="grid grid-cols-2 gap-2.5 text-body">
                                        <span className="rounded-md border p-3.5">
                                            A. x = 4
                                        </span>
                                        <span className="rounded-md border-2 border-primary bg-primary-95 p-3.5 font-semibold dark:bg-primary-20">
                                            B. x = 6
                                        </span>
                                        <span className="rounded-md border p-3.5">
                                            C. x = 8
                                        </span>
                                        <span className="rounded-md border p-3.5">
                                            D. x = 10.6
                                        </span>
                                    </div>
                                </div>
                            </>
                        )}
                        {view === 'parent' && (
                            <>
                                <p className="text-lg font-bold">
                                    Your child this term
                                </p>
                                <div className="grid grid-cols-3 gap-2.5">
                                    <MiniStat
                                        label="Attendance"
                                        value="23 / 24"
                                    />
                                    <MiniStat label="Late" value="1" />
                                    <MiniStat label="Quizzes" value="38" />
                                </div>
                                <div className="flex flex-col gap-2.5 rounded-md border bg-surface p-4">
                                    <MiniBar label="Number" value={82} />
                                    <MiniBar label="Algebra" value={64} />
                                    <MiniBar label="Statistics" value={71} />
                                    <MiniBar label="Geometry" value={58} />
                                </div>
                                <p className="rounded-md bg-primary-95 p-3.5 text-[0.8125rem] text-primary-10 dark:bg-primary-20 dark:text-primary-95">
                                    Tutor’s note: confident with equivalent
                                    fractions — next, practise converting to
                                    decimals.
                                </p>
                            </>
                        )}
                    </div>
                </BrowserFrame>
            </div>
        </div>
    );
}

function StageMatrix() {
    const [stage, setStage] = useState<keyof typeof stages>('ks2');

    return (
        <div className="flex flex-col gap-5 sm:gap-7">
            <Tabs
                label="Choose a stage"
                options={(Object.keys(stages) as (keyof typeof stages)[]).map(
                    (id) => ({ id, label: stages[id].label }),
                )}
                value={stage}
                onChange={setStage}
            />
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stages[stage].cards.map((card) => (
                    <article
                        key={card.title}
                        className="flex flex-col gap-3 rounded-lg border bg-surface p-card sm:p-6"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-xl font-bold">{card.title}</h3>
                            <DifficultyBadge difficulty={card.level} />
                        </div>
                        <p className="text-[0.8125rem] text-muted-foreground">
                            {card.sub}
                        </p>
                        <p className="text-sm leading-relaxed">{card.body}</p>
                    </article>
                ))}
            </div>
        </div>
    );
}

export default function Welcome({ canRegister }: { canRegister: boolean }) {
    const { auth } = usePage().props;

    const primaryCta = auth.user ? (
        <Link href={dashboard()} className={buttonClasses('primary', 'lg')}>
            Go to your dashboard
            <Icon name="arrow-right" className="size-4" />
        </Link>
    ) : canRegister ? (
        <Link href={register()} className={buttonClasses('primary', 'lg')}>
            Create a parent account
            <Icon name="arrow-right" className="size-4" />
        </Link>
    ) : (
        <Link href={login()} className={buttonClasses('primary', 'lg')}>
            Log in
            <Icon name="arrow-right" className="size-4" />
        </Link>
    );

    return (
        <>
            <Head title="Maths quizzes and attendance" />
            <div className="min-h-screen bg-neutral-95 text-foreground dark:bg-background">
                <header className="border-b bg-surface">
                    <div className="mx-auto flex max-w-[1240px] flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3 sm:gap-x-8 sm:px-6 sm:py-3.5">
                        <Link href="/" aria-label="Jerry Great Academy home">
                            <AppLogoImage className="w-32" />
                        </Link>
                        <nav
                            aria-label="Main"
                            className="flex flex-[1_1_360px] flex-wrap gap-x-6 gap-y-1 text-sm font-medium text-muted-foreground"
                        >
                            <a
                                href="#attendance"
                                className="hover:text-foreground"
                            >
                                Attendance
                            </a>
                            <a
                                href="#quizzes"
                                className="hover:text-foreground"
                            >
                                Maths quizzes
                            </a>
                            <a href="#stages" className="hover:text-foreground">
                                Key stages &amp; exam prep
                            </a>
                            <a
                                href="#pricing"
                                className="hover:text-foreground"
                            >
                                Pricing
                            </a>
                        </nav>
                        <div className="flex items-center gap-3">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className={buttonClasses('primary')}
                                >
                                    Go to dashboard
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className={buttonClasses('outline')}
                                    >
                                        Log in
                                    </Link>
                                    {canRegister && (
                                        <Link
                                            href={register()}
                                            className={buttonClasses('primary')}
                                        >
                                            Create account
                                        </Link>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </header>

                <main>
                    <section className="mx-auto grid max-w-[1240px] items-center gap-8 px-4 pt-10 pb-10 sm:px-6 sm:pt-14 sm:pb-14 lg:grid-cols-2 lg:gap-12 lg:pt-20">
                        <div className="flex flex-col gap-6">
                            <span className="inline-flex items-center gap-2 self-start rounded-full bg-secondary-95 px-3 py-1.5 text-[0.8125rem] font-semibold text-secondary-30 dark:bg-secondary-20 dark:text-secondary-90">
                                <Icon name="school" className="size-4" />
                                KS2 · KS3 · 11+ · SATs · GCSE
                            </span>
                            <h1 className="text-display text-brand dark:text-primary-90">
                                Excellence in mathematics and academic
                                accountability
                            </h1>
                            <p className="max-w-xl text-lg leading-7 text-muted-foreground">
                                Year-group maths quizzes from Year 3 to Year 9,
                                with 11+, SATs and GCSE preparation — and a
                                fair, two-way record of attendance and
                                punctuality for every student and tutor.
                            </p>
                            <div className="flex flex-wrap gap-3">
                                {primaryCta}
                                <a
                                    href="#showcase"
                                    className={buttonClasses('accent', 'lg')}
                                >
                                    See the platform
                                </a>
                            </div>
                            <p className="flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
                                <Icon
                                    name="shield"
                                    className="size-4.5 text-primary-40 dark:text-primary-80"
                                />
                                Monthly subscription. Cancel any time.
                            </p>
                        </div>

                        <div className="relative" aria-hidden="true">
                            <BrowserFrame url="Live class register · Year 6 · Fractions">
                                <div className="flex flex-col gap-3 p-4 sm:gap-3.5 sm:p-5">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                                Today · 16:00 – 17:00
                                            </p>
                                            <p className="mt-1 text-lg font-bold">
                                                Year 6 · Fractions and decimals
                                            </p>
                                        </div>
                                        <span className="inline-flex h-6 items-center gap-1 rounded-md bg-present-soft px-2 text-xs font-medium text-present">
                                            <Icon
                                                name="clock"
                                                className="size-3.5"
                                            />
                                            Tutor on time
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="rounded-md bg-primary-95 p-3.5 dark:bg-primary-20">
                                            <p className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                                Tutor
                                            </p>
                                            <p className="mt-1 font-semibold">
                                                Checked in 15:58
                                            </p>
                                        </div>
                                        <div className="rounded-md bg-secondary-95 p-3.5 dark:bg-secondary-20">
                                            <p className="text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                                                Present
                                            </p>
                                            <p className="mt-1 font-semibold">
                                                11 of 12 students
                                            </p>
                                        </div>
                                    </div>
                                    <ul className="flex flex-col">
                                        {(
                                            [
                                                [
                                                    'AO',
                                                    'Student A',
                                                    'present',
                                                    null,
                                                ],
                                                ['JB', 'Student B', 'late', 7],
                                                [
                                                    'ZK',
                                                    'Student C',
                                                    'absent',
                                                    null,
                                                ],
                                            ] as const
                                        ).map(
                                            ([
                                                initials,
                                                name,
                                                status,
                                                minutes,
                                            ]) => (
                                                <li
                                                    key={name}
                                                    className="flex items-center justify-between gap-3 border-b py-2.5 last:border-0"
                                                >
                                                    <span className="flex items-center gap-3">
                                                        <span className="flex size-9 items-center justify-center rounded-full bg-primary-90 text-[0.8125rem] font-semibold text-primary-10 dark:bg-primary-20 dark:text-primary-90">
                                                            {initials}
                                                        </span>
                                                        <span className="font-medium">
                                                            {name}
                                                        </span>
                                                    </span>
                                                    <AttendanceStatusBadge
                                                        status={status}
                                                        minutesLate={minutes}
                                                    />
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            </BrowserFrame>
                            <div className="absolute -bottom-12 left-2 flex items-center gap-3 rounded-lg border bg-surface px-4 py-3.5 shadow-raised sm:-left-6">
                                <span className="flex size-10 items-center justify-center rounded-md bg-secondary-95 text-secondary-40 dark:bg-secondary-20 dark:text-secondary-90">
                                    <Icon name="report" />
                                </span>
                                <div>
                                    <p className="text-sm font-semibold">
                                        Lateness report created
                                    </p>
                                    <p className="text-[0.8125rem] text-muted-foreground">
                                        Student B · arrived 16:07
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        aria-label="What the platform covers"
                        className="mx-auto max-w-[1240px] px-4 pt-4 pb-10 sm:px-6 sm:pt-6 sm:pb-16"
                    >
                        <div className="grid gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-2 lg:grid-cols-4">
                            {facts.map((fact) => (
                                <div
                                    key={fact.value}
                                    className="bg-surface p-4 text-center sm:p-6"
                                >
                                    <p className="text-stat font-extrabold text-brand dark:text-primary-90">
                                        {fact.value}
                                    </p>
                                    <p className="mt-1 text-[0.8125rem] text-muted-foreground">
                                        {fact.label}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section id="showcase" className="border-y bg-surface">
                        <div className="mx-auto flex max-w-[1240px] flex-col gap-6 px-4 py-12 sm:gap-8 sm:px-6 sm:py-16 lg:py-20">
                            <SectionHeading
                                centred
                                eyebrow="Inside the platform"
                                title="One website for the academy, students and parents"
                                body="Every account sees what matters to them — the register and reports for staff, quizzes for students, progress for parents."
                            />
                            <Showcase />
                        </div>
                    </section>

                    <section className="mx-auto flex max-w-[1240px] flex-col gap-6 px-4 pt-12 pb-8 sm:gap-8 sm:px-6 sm:pt-16 lg:gap-10 lg:pt-22 lg:pb-10">
                        <SectionHeading
                            eyebrow="Two connected modules"
                            title="Engineered for academic precision and mutual accountability"
                        />
                        <div className="grid gap-6 lg:grid-cols-2">
                            <article
                                id="attendance"
                                className="flex flex-col gap-4 rounded-[20px] border bg-surface p-5 sm:gap-5 sm:p-6 lg:p-8"
                            >
                                <span className="flex size-10 items-center justify-center rounded-md bg-primary-95 text-primary-30 sm:size-12 dark:bg-primary-20 dark:text-primary-90">
                                    <Icon name="check" className="size-6" />
                                </span>
                                <h3 className="text-section">
                                    Attendance and punctuality
                                </h3>
                                <p className="text-base leading-relaxed text-muted-foreground">
                                    Every class records who attended and whether
                                    they arrived on time — for students and for
                                    tutors alike.
                                </p>
                                <ul className="flex flex-col gap-3 text-body sm:gap-3.5">
                                    {attendancePoints.map((point) => (
                                        <li
                                            key={point.title}
                                            className="flex gap-3"
                                        >
                                            <Icon
                                                name={point.icon}
                                                className="mt-0.5 text-primary-40 dark:text-primary-80"
                                            />
                                            <span>
                                                <strong>{point.title}</strong>{' '}
                                                {point.body}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </article>
                            <article
                                id="quizzes"
                                className="flex flex-col gap-4 rounded-[20px] bg-brand p-5 text-brand-foreground sm:gap-5 sm:p-6 lg:p-8"
                            >
                                <span className="flex size-10 items-center justify-center rounded-md bg-secondary-key text-primary-10 sm:size-12">
                                    <Icon
                                        name="calculator"
                                        className="size-6"
                                    />
                                </span>
                                <h3 className="text-section">
                                    Maths quiz platform
                                </h3>
                                <p className="text-base leading-relaxed text-primary-90">
                                    Maths only, matched to each year group, with
                                    difficulty that rises as students move up —
                                    drawn from a large question bank so quizzes
                                    don’t repeat.
                                </p>
                                <div className="grid grid-cols-2 gap-3">
                                    {topics.map((topic) => (
                                        <div
                                            key={topic}
                                            className="rounded-md bg-primary-20 p-3.5 font-semibold"
                                        >
                                            {topic}
                                        </div>
                                    ))}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <DifficultyBadge difficulty="easy" />
                                    <DifficultyBadge difficulty="intermediate" />
                                    <span className="rounded-md bg-white">
                                        <DifficultyBadge difficulty="hard" />
                                    </span>
                                    <DifficultyBadge difficulty="challenging" />
                                </div>
                            </article>
                        </div>
                    </section>

                    <section
                        id="stages"
                        className="mx-auto flex max-w-[1240px] flex-col gap-6 px-4 pt-8 pb-12 sm:gap-8 sm:px-6 sm:pt-12 lg:pb-22"
                    >
                        <SectionHeading
                            centred
                            eyebrow="Key stage and exam matrix"
                            title="The right level for every year group"
                        />
                        <StageMatrix />
                    </section>

                    <section className="border-y bg-surface">
                        <div className="mx-auto grid max-w-[1240px] items-center gap-8 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-2 lg:gap-12 lg:py-20">
                            <SectionHeading
                                eyebrow="End-of-class reports"
                                title="An equal voice for student and tutor"
                                body="After every class, both sides can give feedback and make recommendations. The academy sees two points of view on each lesson — what worked, what to improve, and what to raise."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <article className="flex flex-col gap-3 rounded-lg border border-primary-90 bg-primary-95 p-card sm:p-6 dark:border-primary-20 dark:bg-primary-20/40">
                                    <p className="font-semibold">
                                        Tutor report
                                    </p>
                                    <p className="text-body">
                                        “Good focus on equivalent fractions. Two
                                        students need more practice converting
                                        to decimals.”
                                    </p>
                                    <p className="text-[0.8125rem] font-semibold text-primary-30 dark:text-primary-80">
                                        Recommends: a decimals quiz
                                    </p>
                                </article>
                                <article className="flex flex-col gap-3 rounded-lg border border-secondary-90 bg-secondary-95 p-6 dark:border-secondary-20 dark:bg-secondary-20/40">
                                    <p className="font-semibold">
                                        Student report
                                    </p>
                                    <p className="text-body">
                                        “The worked examples helped. I’d like
                                        more time on word problems.”
                                    </p>
                                    <p className="text-[0.8125rem] font-semibold text-secondary-30 dark:text-secondary-80">
                                        Recommends: more word problems
                                    </p>
                                </article>
                            </div>
                        </div>
                    </section>

                    <section
                        id="pricing"
                        className="mx-auto flex max-w-[1240px] flex-col items-center gap-6 px-4 py-12 sm:gap-8 sm:px-6 sm:py-16 lg:py-22"
                    >
                        <SectionHeading
                            centred
                            eyebrow="Monthly subscription"
                            title="Complete maths platform access"
                            body="One monthly subscription per child, paid in naira. Cancel any time from your billing page."
                        />
                        <article className="flex w-full max-w-md flex-col gap-5 rounded-[20px] border-2 border-secondary-key bg-surface p-5 sm:p-6 lg:p-8">
                            <div>
                                <p className="text-xl font-bold">
                                    Monthly membership
                                </p>
                                <p className="mt-1 text-[0.8125rem] text-muted-foreground">
                                    Per child
                                </p>
                            </div>
                            <p>
                                <span className="text-display text-brand dark:text-primary-90">
                                    {MONTHLY_PRICE}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    / month
                                </span>
                            </p>
                            <ul className="flex flex-col gap-3 text-body">
                                {planFeatures.map((feature) => (
                                    <li key={feature} className="flex gap-2.5">
                                        <Icon
                                            name="check"
                                            className="text-primary-40 dark:text-primary-80"
                                        />
                                        {feature}
                                    </li>
                                ))}
                            </ul>
                            {canRegister && !auth.user && (
                                <Link
                                    href={register()}
                                    className={buttonClasses(
                                        'primary',
                                        'lg',
                                        'w-full',
                                    )}
                                >
                                    Create a parent account
                                </Link>
                            )}
                        </article>
                    </section>

                    <section className="px-4 pb-12 sm:px-6 sm:pb-16 lg:pb-22">
                        <div className="relative mx-auto flex max-w-[1192px] flex-col items-center gap-5 overflow-hidden rounded-[20px] bg-brand px-5 py-10 text-center text-brand-foreground sm:rounded-[28px] sm:px-8 sm:py-14 lg:py-16">
                            <span
                                aria-hidden="true"
                                className="absolute -top-16 -right-16 size-56 rounded-bl-full bg-secondary-key"
                            />
                            <span
                                aria-hidden="true"
                                className="absolute -bottom-10 -left-10 size-40 rounded-tr-full bg-tertiary-30"
                            />
                            <h2 className="relative max-w-3xl text-headline font-extrabold">
                                Build real mathematical confidence, one year at
                                a time
                            </h2>
                            <p className="relative max-w-xl text-lead text-primary-90">
                                Create an account to see your child’s quizzes,
                                attendance and class reports in one place.
                            </p>
                            <div className="relative flex flex-wrap justify-center gap-3">
                                {canRegister && !auth.user && (
                                    <Link
                                        href={register()}
                                        className={buttonClasses(
                                            'accent',
                                            'lg',
                                        )}
                                    >
                                        Create an account
                                    </Link>
                                )}
                                <Link
                                    href={auth.user ? dashboard() : login()}
                                    className={buttonClasses(
                                        'outline',
                                        'lg',
                                        'border-primary-70 text-white hover:bg-primary-20 hover:text-white',
                                    )}
                                >
                                    {auth.user ? 'Go to dashboard' : 'Log in'}
                                </Link>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t bg-surface">
                    <div className="mx-auto flex max-w-[1240px] flex-wrap items-center justify-between gap-4 px-4 py-8 sm:gap-6 sm:px-6 sm:py-10">
                        <AppLogoImage className="w-28" />
                        <p className="text-[0.8125rem] text-muted-foreground">
                            © {new Date().getFullYear()} Jerry Great Academy
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
