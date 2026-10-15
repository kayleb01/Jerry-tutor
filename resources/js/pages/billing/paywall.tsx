import { Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import { buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as billingIndex } from '@/routes/billing';
import { dashboard } from '@/routes';

type PaywallProps = {
    guardians: string[];
    canPay: boolean;
};

function list(names: string[]): string {
    return names.length <= 1
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} or ${names[names.length - 1]}`;
}

/** Shown on quiz pages to a student without a subscription. */
export default function Paywall({ guardians, canPay }: PaywallProps) {
    return (
        <AppLayout title="Quizzes" subtitle="Practice">
            <Card className="mx-auto flex w-full max-w-xl flex-col items-center gap-4 p-card py-10 text-center sm:py-12">
                <span className="flex size-12 items-center justify-center rounded-full bg-primary-95 text-primary-30 dark:bg-primary-20 dark:text-primary-90">
                    <Icon name="lock" className="size-6" />
                </span>
                <div className="flex flex-col gap-2">
                    <h2 className="text-section font-bold">
                        Quizzes need a subscription
                    </h2>
                    <p className="text-body text-muted-foreground">
                        {canPay
                            ? 'Subscribe to unlock quizzes and exam practice for your year group.'
                            : `Ask ${list(guardians)} to subscribe for you on their Billing page. Your quizzes unlock as soon as they do.`}
                    </p>
                </div>
                {canPay ? (
                    <Link
                        href={billingIndex()}
                        className={buttonClasses('primary')}
                    >
                        See plans
                    </Link>
                ) : (
                    <Link
                        href={dashboard()}
                        className={buttonClasses('outline')}
                    >
                        Back to your dashboard
                    </Link>
                )}
            </Card>
        </AppLayout>
    );
}
