import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import LiveClock from '@/components/live-clock';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { store } from '@/routes/check-in';

type Stamp = {
    time: string | null;
    status: string;
    minutesLate: number | null;
    recordedBySelf: boolean;
};

type CheckInCard = {
    lessonId: number;
    title: string;
    startsAt: string;
    stamp: Stamp | null;
};

type CheckInProps = {
    card: CheckInCard | null;
    next: string | null;
    timezone: string;
};

const statusWords: Record<string, string> = {
    present: 'present',
    late: 'late',
    absent: 'absent',
    excused: 'excused',
};

function stampLine(stamp: Stamp): string {
    if (stamp.time === null) {
        return `Marked ${statusWords[stamp.status] ?? stamp.status} by your tutor`;
    }

    const when =
        stamp.status === 'late' && stamp.minutesLate
            ? `${stamp.minutesLate} min late`
            : 'On time';

    return `Checked in at ${stamp.time} · ${when}`;
}

export default function CheckIn({ card, next, timezone }: CheckInProps) {
    return (
        <AppLayout title="Check in" subtitle="Mark yourself present">
            <Card className="mx-auto flex w-full max-w-md flex-col items-center gap-5 p-6 text-center sm:p-8">
                {card === null ? (
                    <>
                        <LiveClock timezone={timezone} />
                        <p className="text-section">
                            No class to check in to right now
                        </p>
                        {next && (
                            <p className="text-body text-muted-foreground">
                                Next: {next}
                            </p>
                        )}
                    </>
                ) : (
                    <>
                        <div>
                            <h2 className="text-section">{card.title}</h2>
                            <p className="mt-1 text-body text-muted-foreground">
                                Class starts {card.startsAt}
                            </p>
                        </div>
                        <LiveClock
                            timezone={timezone}
                            frozenAt={card.stamp?.time ?? null}
                        />
                        {card.stamp ? (
                            <p className="inline-flex items-center gap-2 rounded-full bg-present-soft px-4 py-2 text-sm font-semibold text-present">
                                <Icon name="check" className="size-4" />
                                {stampLine(card.stamp)}
                            </p>
                        ) : (
                            <Form
                                {...store.form(card.lessonId)}
                                className="flex w-full flex-col gap-3"
                                resetOnError
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Label
                                            htmlFor="code"
                                            className="text-body"
                                        >
                                            Code on your tutor&rsquo;s screen
                                        </Label>
                                        <Input
                                            id="code"
                                            name="code"
                                            inputMode="numeric"
                                            pattern="[0-9]*"
                                            maxLength={4}
                                            autoComplete="one-time-code"
                                            required
                                            aria-invalid={!!errors.code}
                                            aria-describedby={
                                                errors.code
                                                    ? 'code-error'
                                                    : undefined
                                            }
                                            className="h-14 text-center text-2xl font-bold tracking-[0.5em] tabular-nums"
                                        />
                                        <FieldError
                                            id="code-error"
                                            message={errors.code}
                                        />
                                        <Button
                                            type="submit"
                                            size="lg"
                                            disabled={processing}
                                            className="w-full"
                                        >
                                            Check in
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </>
                )}
            </Card>
        </AppLayout>
    );
}
