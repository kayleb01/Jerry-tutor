import { Head, Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { dashboard, login, register } from '@/routes';

const highlights = [
    {
        title: 'KS2 & KS3 maths',
        body: 'Quizzes matched to every year group, from Year 3 to Year 9.',
    },
    {
        title: '11+, SATs & GCSE',
        body: 'Focused exam practice when it matters most.',
    },
    {
        title: 'Attendance you can trust',
        body: 'On-time records for every class, for students and tutors alike.',
    },
];

export default function Welcome({ canRegister }: { canRegister: boolean }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="min-h-screen bg-background">
                <header className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
                    <AppLogo className="text-lg" />
                    <nav className="flex items-center gap-4">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="text-sm font-medium text-primary"
                            >
                                Go to dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="text-sm font-medium text-foreground"
                                >
                                    Log in
                                </Link>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="inline-flex h-10 items-center rounded-md bg-accent px-4 text-sm font-medium text-accent-foreground shadow-card hover:bg-accent-hover"
                                    >
                                        Sign up
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </header>
                <main className="mx-auto flex max-w-6xl flex-col gap-12 px-4 py-16">
                    <section className="flex flex-col gap-4">
                        <h1 className="font-display text-4xl leading-tight font-bold tracking-wide text-primary uppercase sm:text-6xl">
                            Confident in maths. On time, every time.
                        </h1>
                        <p className="max-w-2xl text-lg text-muted-foreground">
                            Jerry Great Academy brings year-group maths quizzes,
                            exam preparation and fair attendance tracking
                            together in one place.
                        </p>
                    </section>
                    <section className="grid gap-4 md:grid-cols-3">
                        {highlights.map((item) => (
                            <div
                                key={item.title}
                                className="rounded-lg border bg-surface p-5 shadow-card"
                            >
                                <h2 className="font-semibold">{item.title}</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {item.body}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>
            </div>
        </>
    );
}
