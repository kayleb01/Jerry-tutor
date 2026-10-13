import { Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as adminClassesIndex } from '@/routes/admin/classes';
import { index as adminLatenessIndex } from '@/routes/admin/lateness';
import { index as adminLessonReportsIndex } from '@/routes/admin/lesson-reports';
import { index as adminUsersIndex } from '@/routes/admin/users';

type Area = {
    title: string;
    description: string;
    cta: string;
    icon: IconName;
    href: () => { url: string; method: 'get' };
    tone: string;
};

const areas: Area[] = [
    {
        title: 'Users',
        description:
            'Create tutor, student and parent accounts, and deactivate ones that are no longer needed.',
        cta: 'Manage users',
        icon: 'users',
        href: adminUsersIndex,
        tone: 'bg-primary-95 text-primary-30 dark:bg-primary-20 dark:text-primary-90',
    },
    {
        title: 'Classes',
        description:
            'Set up recurring classes, assign tutors and enrol students. Lessons are scheduled four weeks ahead.',
        cta: 'Manage classes',
        icon: 'classes',
        href: adminClassesIndex,
        tone: 'bg-primary-95 text-primary-30 dark:bg-primary-20 dark:text-primary-90',
    },
    {
        title: 'Lateness reports',
        description:
            'Created automatically whenever a student or tutor is late. Follow up on repeat lateness.',
        cta: 'Review lateness',
        icon: 'alarm',
        href: adminLatenessIndex,
        tone: 'bg-secondary-95 text-secondary-40 dark:bg-secondary-20 dark:text-secondary-90',
    },
    {
        title: 'End-of-class reports',
        description:
            'Feedback and recommendations from both tutors and students after every lesson.',
        cta: 'Read reports',
        icon: 'report',
        href: adminLessonReportsIndex,
        tone: 'bg-secondary-95 text-secondary-40 dark:bg-secondary-20 dark:text-secondary-90',
    },
];

export default function AdminDashboard() {
    return (
        <AppLayout title="Admin dashboard" subtitle="Jerry Great Academy">
            <section className="relative overflow-hidden rounded-lg bg-brand p-7 text-brand-foreground">
                <span
                    aria-hidden="true"
                    className="absolute -top-10 -right-10 size-40 rounded-bl-full bg-secondary-key"
                />
                <p className="relative text-xs font-semibold tracking-[0.08em] text-secondary-80 uppercase">
                    Attendance and maths quizzes
                </p>
                <h2 className="relative mt-2 max-w-xl text-2xl leading-8 font-bold">
                    Everything the academy runs on, in one place
                </h2>
                <p className="relative mt-2 max-w-xl text-[15px] text-primary-90">
                    Registers, lateness and class reports apply to students and
                    tutors alike.
                </p>
            </section>
            <div className="grid gap-4 md:grid-cols-2">
                {areas.map((area) => (
                    <Card key={area.title} className="flex flex-col gap-4 p-6">
                        <span
                            className={`flex size-12 items-center justify-center rounded-md ${area.tone}`}
                        >
                            <Icon name={area.icon} className="size-6" />
                        </span>
                        <div className="flex flex-col gap-1">
                            <h2 className="text-lg font-bold">{area.title}</h2>
                            <p className="text-sm text-muted-foreground">
                                {area.description}
                            </p>
                        </div>
                        <Link
                            href={area.href()}
                            className="mt-auto inline-flex items-center gap-1.5 self-start text-sm font-semibold text-link hover:underline"
                        >
                            {area.cta}
                            <Icon name="arrow-right" className="size-4" />
                        </Link>
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}
