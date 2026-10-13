import { Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import { buttonClasses } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index as childrenIndex } from '@/routes/children';

export default function GuardianDashboard() {
    return (
        <AppLayout title="Your family" subtitle="Parent overview">
            <section className="relative flex flex-col gap-4 overflow-hidden rounded-lg bg-brand p-7 text-brand-foreground">
                <span
                    aria-hidden="true"
                    className="absolute -top-10 -right-10 size-40 rounded-bl-full bg-secondary-key"
                />
                <span className="relative flex size-12 items-center justify-center rounded-md bg-secondary-key text-primary-10">
                    <Icon name="children" className="size-6" />
                </span>
                <div className="relative flex max-w-xl flex-col gap-2">
                    <h2 className="text-2xl leading-8 font-bold">
                        Add your children
                    </h2>
                    <p className="text-[15px] text-primary-90">
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
