import { Link } from '@inertiajs/react';
import FromAcademy from '@/components/from-academy';
import Icon from '@/components/icon';
import { buttonClasses } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index as childrenIndex } from '@/routes/children';
import { acknowledge as acknowledgeNotice } from '@/routes/guardian-notices';

type GuardianDashboardProps = {
    fromAcademy: {
        id: number;
        childName: string;
        when: string;
        note: string;
        sentBy: string | null;
    }[];
};

export default function GuardianDashboard({
    fromAcademy,
}: GuardianDashboardProps) {
    return (
        <AppLayout title="Your family" subtitle="Parent overview">
            <FromAcademy
                notes={fromAcademy.map((notice) => ({
                    id: notice.id,
                    heading: `${notice.childName} · ${notice.when}`,
                    note: notice.note,
                    from: notice.sentBy,
                }))}
                description="Notes about your children from the academy office."
                acknowledge={(id) => acknowledgeNotice.form(id)}
            />
            <section className="relative flex flex-col gap-4 overflow-hidden rounded-lg bg-brand p-card text-brand-foreground sm:p-7">
                <span
                    aria-hidden="true"
                    className="absolute -top-8 -right-8 size-24 rounded-bl-full bg-secondary-key sm:-top-10 sm:-right-10 sm:size-40"
                />
                <span className="relative flex size-10 shrink-0 items-center justify-center rounded-md bg-secondary-key text-primary-10 sm:size-12">
                    <Icon name="children" className="size-6" />
                </span>
                <div className="relative flex max-w-xl flex-col gap-2">
                    <h2 className="text-section">Add your children</h2>
                    <p className="text-body text-primary-90">
                        Add your children so they can log in. You’ll see their
                        attendance and punctuality here, and soon their quiz
                        progress.
                    </p>
                </div>
                <Link
                    href={childrenIndex()}
                    className={buttonClasses(
                        'accent',
                        'lg',
                        'relative self-start',
                    )}
                >
                    Manage children
                    <Icon name="arrow-right" className="size-4" />
                </Link>
            </section>
        </AppLayout>
    );
}
