import { Form } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import PersonAvatar from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { notifyParent, update } from '@/routes/admin/lateness';

export type LatenessDetail = {
    id: number;
    name: string;
    role: 'student' | 'tutor';
    roleLabel: string;
    yearGroup: string | null;
    className: string;
    date: string;
    scheduled: string;
    arrived: string;
    minutesLate: number;
    status: 'new' | 'followed';
    reason: string | null;
    followUpNotes: string | null;
    followedUp: { by: string | null; at: string } | null;
    history: {
        items: { date: string; className: string; minutesLate: number }[];
        total: number;
    };
    repeatCount: number;
    parentMessage: string | null;
    canNotifyParent: boolean;
    guardians: string[];
    notices: {
        id: number;
        note: string;
        sentBy: string | null;
        sentAt: string;
        acknowledgedAt: string | null;
        acknowledgedBy: string | null;
    }[];
};

const REPEAT_THRESHOLD = 3;

const smallCaps =
    'text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

type LatenessReportDetailProps = {
    report: LatenessDetail;
    /** The report to open after "Mark as followed up", if any. */
    nextReportId: number | null;
};

/** One lateness report with the person's recent history and the follow-up actions. */
export default function LatenessReportDetail({
    report,
    nextReportId,
}: LatenessReportDetailProps) {
    const [notifying, setNotifying] = useState(false);
    const earlier = report.history.total - 1 - report.history.items.length;
    const tiles = [
        { label: 'Scheduled start', value: report.scheduled },
        { label: 'Actual arrival', value: report.arrived, highlight: true },
        { label: 'Date', value: report.date },
        {
            label: 'Minutes late',
            value: `${report.minutesLate} ${report.minutesLate === 1 ? 'minute' : 'minutes'}`,
        },
    ];

    return (
        <Card className="flex min-w-0 flex-col gap-5 p-card">
            <div className="flex items-center gap-3">
                <PersonAvatar
                    name={report.name}
                    role={report.role}
                    className="size-12 text-sm"
                />
                <div className="min-w-0">
                    <h2 className="truncate text-lg font-bold sm:text-xl">
                        {report.name}
                    </h2>
                    <p className="text-[13px] text-muted-foreground sm:text-sm">
                        {[report.roleLabel, report.yearGroup, report.className]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-2">
                {tiles.map((tile) => (
                    <div
                        key={tile.label}
                        className={cn(
                            'rounded-md px-3.5 py-3',
                            tile.highlight
                                ? 'bg-secondary-95 dark:bg-secondary-20/50'
                                : 'bg-surface-muted',
                        )}
                    >
                        <dt className={smallCaps}>{tile.label}</dt>
                        <dd className="mt-1 text-base font-bold sm:text-lg">
                            {tile.value}
                        </dd>
                    </div>
                ))}
            </dl>

            {report.reason && (
                <div>
                    <p className={smallCaps}>Reason given</p>
                    <p className="mt-1 text-sm">{report.reason}</p>
                </div>
            )}

            <section aria-labelledby="history-heading">
                <h3 id="history-heading" className="text-sm font-semibold">
                    Recent lateness{' '}
                    <span className="font-normal text-muted-foreground">
                        (last 90 days)
                    </span>
                </h3>
                {report.history.items.length === 0 ? (
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        No other lateness in the last 90 days.
                    </p>
                ) : (
                    <ul className="mt-1.5 flex flex-col gap-1 text-sm">
                        {report.history.items.map((item, index) => (
                            <li
                                key={`${item.date}-${index}`}
                                className="flex justify-between gap-3"
                            >
                                <span className="min-w-0 truncate">
                                    {item.date} · {item.className}
                                </span>
                                <span className="shrink-0 text-muted-foreground tabular-nums">
                                    {item.minutesLate} min
                                </span>
                            </li>
                        ))}
                        {earlier > 0 && (
                            <li className="text-[13px] text-muted-foreground">
                                and {earlier} more
                            </li>
                        )}
                    </ul>
                )}
            </section>

            {report.repeatCount >= REPEAT_THRESHOLD && (
                <p
                    role="status"
                    className="flex gap-3 rounded-md bg-secondary-95 px-4 py-3.5 text-sm text-secondary-10 dark:bg-secondary-20 dark:text-secondary-90"
                >
                    <span aria-hidden="true" className="font-bold">
                        !
                    </span>
                    {report.name} has been late {report.repeatCount} times in
                    the last 30 days.
                </p>
            )}

            <Form
                {...update.form(report.id)}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-3"
            >
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="reviewed" value="1" />
                        {!report.followedUp && nextReportId !== null && (
                            <input
                                type="hidden"
                                name="next"
                                value={nextReportId}
                            />
                        )}
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="follow_up_notes">
                                Follow-up note
                            </Label>
                            <Textarea
                                id="follow_up_notes"
                                name="follow_up_notes"
                                rows={4}
                                maxLength={2000}
                                defaultValue={report.followUpNotes ?? ''}
                                placeholder="What was agreed, and with whom"
                                aria-invalid={!!errors.follow_up_notes}
                                aria-describedby="follow_up_notes-error"
                            />
                            <FieldError
                                id="follow_up_notes-error"
                                message={errors.follow_up_notes}
                            />
                        </div>
                        {report.followedUp && (
                            <p className="text-[13px] text-muted-foreground">
                                Followed up by{' '}
                                {report.followedUp.by ?? 'an admin'},{' '}
                                {report.followedUp.at}
                            </p>
                        )}
                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={processing}
                        >
                            {report.followedUp
                                ? 'Save note'
                                : 'Mark as followed up'}
                        </Button>
                    </>
                )}
            </Form>

            {report.followedUp && (
                <Form
                    {...update.form(report.id)}
                    options={{ preserveScroll: true }}
                >
                    <input type="hidden" name="reviewed" value="0" />
                    <input
                        type="hidden"
                        name="follow_up_notes"
                        value={report.followUpNotes ?? ''}
                    />
                    <Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        className="w-full"
                    >
                        Reopen as new
                    </Button>
                </Form>
            )}

            {report.role === 'student' &&
                (report.canNotifyParent ? (
                    notifying ? (
                        <Form
                            {...notifyParent.form(report.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setNotifying(false)}
                            className="flex flex-col gap-3 rounded-md border p-3.5"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="parent_note">
                                            Message to{' '}
                                            {report.guardians.join(' and ')}
                                        </Label>
                                        <Textarea
                                            id="parent_note"
                                            name="note"
                                            rows={3}
                                            maxLength={1000}
                                            required
                                            autoFocus
                                            defaultValue={
                                                report.parentMessage ?? ''
                                            }
                                            aria-invalid={!!errors.note}
                                            aria-describedby="parent_note-hint parent_note-error"
                                        />
                                        <p
                                            id="parent_note-hint"
                                            className="text-[13px] text-muted-foreground"
                                        >
                                            Shown on their dashboard until they
                                            mark it as read. Sending it also
                                            marks this report as followed up.
                                        </p>
                                        <FieldError
                                            id="parent_note-error"
                                            message={errors.note}
                                        />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Send to parent
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            onClick={() => setNotifying(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    ) : (
                        <Button
                            variant="outline"
                            size="lg"
                            className="w-full"
                            onClick={() => setNotifying(true)}
                        >
                            Notify parent
                        </Button>
                    )
                ) : (
                    <p className="text-[13px] text-muted-foreground">
                        No parent or guardian is linked to {report.name} yet, so
                        they can’t be notified here.
                    </p>
                ))}

            {report.notices.length > 0 && (
                <section aria-labelledby="notices-heading">
                    <h3 id="notices-heading" className="text-sm font-semibold">
                        Sent to parents
                    </h3>
                    <ul className="mt-2 flex flex-col gap-2">
                        {report.notices.map((notice) => (
                            <li
                                key={notice.id}
                                className="rounded-md bg-surface-muted px-3.5 py-3 text-sm"
                            >
                                <p className="break-words">{notice.note}</p>
                                <p className="mt-1 text-[13px] text-muted-foreground">
                                    {notice.sentBy ?? 'An admin'},{' '}
                                    {notice.sentAt} ·{' '}
                                    {notice.acknowledgedAt
                                        ? `Read by ${notice.acknowledgedBy ?? 'a parent'} ${notice.acknowledgedAt}`
                                        : 'Not read yet'}
                                </p>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </Card>
    );
}
