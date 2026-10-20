import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { approve, index, reject } from '@/routes/admin/tutor-applications';
import type { Option } from '@/types';

type Action = 'assign' | 'handover' | 'leave' | 'create' | 'skip';

type SlotRow = {
    slotId: number;
    timeLabel: string;
    currentTutor: string | null;
};

type RequestRow = {
    requestId: number;
    label: string;
    slots: SlotRow[];
};

type Applicant = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    appliedOn: string;
    status: 'pending' | 'rejected';
};

type Decision = {
    request_id: number;
    slot_id: number | null;
    action: Action;
    weekday: string;
    start_time: string;
    duration_minutes: string;
    location: string;
};

type ShowApplicationProps = {
    applicant: Applicant;
    rows: RequestRow[];
    weekdays: Option[];
};

/** One decision per listed slot, and one per request with no slot, in page order. */
function initialDecisions(rows: RequestRow[]): Decision[] {
    const blank = {
        weekday: '',
        start_time: '',
        duration_minutes: '60',
        location: '',
    };

    return rows.flatMap((row): Decision[] =>
        row.slots.length === 0
            ? [
                  {
                      request_id: row.requestId,
                      slot_id: null,
                      action: 'skip' as const,
                      ...blank,
                  },
              ]
            : row.slots.map((slot) => ({
                  request_id: row.requestId,
                  slot_id: slot.slotId,
                  action: slot.currentTutor
                      ? ('leave' as const)
                      : ('skip' as const),
                  ...blank,
              })),
    );
}

export default function ShowApplication({
    applicant,
    rows,
    weekdays,
}: ShowApplicationProps) {
    const form = useForm<{ decisions: Decision[] }>({
        decisions: initialDecisions(rows),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const firstName = applicant.name.split(/\s+/)[0];

    const update = (index: number, changes: Partial<Decision>) =>
        form.setData(
            'decisions',
            form.data.decisions.map((decision, position) =>
                position === index ? { ...decision, ...changes } : decision,
            ),
        );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            decisions: data.decisions.map((decision) =>
                decision.action === 'create'
                    ? decision
                    : {
                          request_id: decision.request_id,
                          slot_id: decision.slot_id,
                          action: decision.action,
                      },
            ),
        }));
        form.post(approve.url(applicant.id), { preserveScroll: true });
    }

    /** Where each row's decisions start in the flat decisions list. */
    const offsets = rows.map((_, rowIndex) =>
        rows
            .slice(0, rowIndex)
            .reduce((total, row) => total + Math.max(row.slots.length, 1), 0),
    );

    return (
        <AppLayout
            title={applicant.name}
            subtitle="Tutor application"
            badge={applicant.status === 'rejected' ? 'Rejected' : undefined}
        >
            <Link
                href={index({
                    query:
                        applicant.status === 'rejected'
                            ? { status: 'rejected' }
                            : {},
                })}
                className="self-start text-sm font-medium text-link underline-offset-4 hover:underline"
            >
                Back to applications
            </Link>
            <Card>
                <CardContent className="grid gap-4 text-sm sm:grid-cols-4">
                    <div className="min-w-0">
                        <p className="text-muted-foreground">Email</p>
                        <p className="font-medium break-words">
                            {applicant.email}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Phone</p>
                        <p className="font-medium">{applicant.phone ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Applied</p>
                        <p className="font-medium">{applicant.appliedOn}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Status</p>
                        <p className="font-medium">
                            {applicant.status === 'rejected'
                                ? 'Rejected'
                                : 'Waiting for review'}
                        </p>
                    </div>
                </CardContent>
            </Card>
            <form onSubmit={submit} className="flex flex-col gap-6">
                {rows.map((row, rowIndex) => (
                    <Card key={row.requestId}>
                        <CardHeader>
                            <CardTitle>{row.label}</CardTitle>
                            <CardDescription>
                                {row.slots.length === 0
                                    ? `No ${row.label} slot yet.`
                                    : `${firstName} asked to teach this.`}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col divide-y">
                            {(row.slots.length === 0 ? [null] : row.slots).map(
                                (slot, slotIndex) => {
                                    const index = offsets[rowIndex] + slotIndex;
                                    const decision = form.data.decisions[index];
                                    const name = `decision-${index}`;
                                    const choices: {
                                        value: Action;
                                        label: string;
                                    }[] =
                                        slot === null
                                            ? [
                                                  {
                                                      value: 'create',
                                                      label: 'Create a slot',
                                                  },
                                                  {
                                                      value: 'skip',
                                                      label: 'Skip',
                                                  },
                                              ]
                                            : slot.currentTutor
                                              ? [
                                                    {
                                                        value: 'handover',
                                                        label: `Hand over to ${applicant.name}`,
                                                    },
                                                    {
                                                        value: 'leave',
                                                        label: `Leave with ${slot.currentTutor}`,
                                                    },
                                                ]
                                              : [
                                                    {
                                                        value: 'assign',
                                                        label: `Assign to ${applicant.name}`,
                                                    },
                                                    {
                                                        value: 'skip',
                                                        label: 'Skip',
                                                    },
                                                ];
                                    const fieldError = (field: string) =>
                                        errors[`decisions.${index}.${field}`];
                                    const rowError =
                                        fieldError('action') ??
                                        fieldError('slot_id') ??
                                        fieldError('request_id');

                                    return (
                                        <fieldset
                                            key={slot?.slotId ?? 'new'}
                                            className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0"
                                        >
                                            <legend className="sr-only">
                                                {row.label}
                                                {slot
                                                    ? ` ${slot.timeLabel}`
                                                    : ''}
                                            </legend>
                                            {slot && (
                                                <p className="text-sm">
                                                    <span className="font-medium">
                                                        {slot.timeLabel}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {' · '}
                                                        {slot.currentTutor
                                                            ? `taught by ${slot.currentTutor}`
                                                            : 'no tutor'}
                                                    </span>
                                                </p>
                                            )}
                                            <div className="flex flex-wrap gap-2">
                                                {choices.map((choice) => (
                                                    <label
                                                        key={choice.value}
                                                        className={cn(
                                                            'inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition-colors',
                                                            decision.action ===
                                                                choice.value
                                                                ? 'border-primary bg-primary-90 text-primary-10 dark:bg-primary-20 dark:text-primary-90'
                                                                : 'hover:bg-surface-muted',
                                                        )}
                                                    >
                                                        <input
                                                            type="radio"
                                                            name={name}
                                                            value={choice.value}
                                                            checked={
                                                                decision.action ===
                                                                choice.value
                                                            }
                                                            onChange={() =>
                                                                update(index, {
                                                                    action: choice.value,
                                                                })
                                                            }
                                                            className="accent-primary"
                                                        />
                                                        {choice.label}
                                                    </label>
                                                ))}
                                            </div>
                                            <FieldError
                                                id={`${name}-error`}
                                                message={rowError}
                                            />
                                            {decision.action === 'create' && (
                                                <div className="grid gap-4 sm:grid-cols-4">
                                                    <div className="flex flex-col gap-1.5">
                                                        <Label
                                                            htmlFor={`${name}-weekday`}
                                                        >
                                                            Day
                                                        </Label>
                                                        <Select
                                                            id={`${name}-weekday`}
                                                            value={
                                                                decision.weekday
                                                            }
                                                            onChange={(event) =>
                                                                update(index, {
                                                                    weekday:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                            required
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'weekday',
                                                                )
                                                            }
                                                            aria-describedby={`${name}-weekday-error`}
                                                        >
                                                            <option
                                                                value=""
                                                                disabled
                                                            >
                                                                Choose a day
                                                            </option>
                                                            {weekdays.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </Select>
                                                        <FieldError
                                                            id={`${name}-weekday-error`}
                                                            message={fieldError(
                                                                'weekday',
                                                            )}
                                                        />
                                                    </div>
                                                    <div className="flex flex-col gap-1.5">
                                                        <Label
                                                            htmlFor={`${name}-start`}
                                                        >
                                                            Start time
                                                        </Label>
                                                        <Input
                                                            id={`${name}-start`}
                                                            type="time"
                                                            value={
                                                                decision.start_time
                                                            }
                                                            onChange={(event) =>
                                                                update(index, {
                                                                    start_time:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                            required
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'start_time',
                                                                )
                                                            }
                                                            aria-describedby={`${name}-start-error`}
                                                        />
                                                        <FieldError
                                                            id={`${name}-start-error`}
                                                            message={fieldError(
                                                                'start_time',
                                                            )}
                                                        />
                                                    </div>
                                                    <div className="flex flex-col gap-1.5">
                                                        <Label
                                                            htmlFor={`${name}-length`}
                                                        >
                                                            Length (minutes)
                                                        </Label>
                                                        <Input
                                                            id={`${name}-length`}
                                                            type="number"
                                                            min={15}
                                                            max={240}
                                                            step={5}
                                                            value={
                                                                decision.duration_minutes
                                                            }
                                                            onChange={(event) =>
                                                                update(index, {
                                                                    duration_minutes:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                            required
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'duration_minutes',
                                                                )
                                                            }
                                                            aria-describedby={`${name}-length-error`}
                                                        />
                                                        <FieldError
                                                            id={`${name}-length-error`}
                                                            message={fieldError(
                                                                'duration_minutes',
                                                            )}
                                                        />
                                                    </div>
                                                    <div className="flex flex-col gap-1.5">
                                                        <Label
                                                            htmlFor={`${name}-room`}
                                                        >
                                                            Room (optional)
                                                        </Label>
                                                        <Input
                                                            id={`${name}-room`}
                                                            value={
                                                                decision.location
                                                            }
                                                            onChange={(event) =>
                                                                update(index, {
                                                                    location:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                            placeholder="e.g. Room 2"
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'location',
                                                                )
                                                            }
                                                            aria-describedby={`${name}-room-error`}
                                                        />
                                                        <FieldError
                                                            id={`${name}-room-error`}
                                                            message={fieldError(
                                                                'location',
                                                            )}
                                                        />
                                                    </div>
                                                </div>
                                            )}
                                        </fieldset>
                                    );
                                },
                            )}
                        </CardContent>
                    </Card>
                ))}
                <FieldError id="decisions-error" message={errors.decisions} />
                <div className="flex flex-wrap gap-3">
                    <Button type="submit" disabled={form.processing}>
                        Approve
                    </Button>
                    {applicant.status === 'pending' && (
                        <Link
                            href={reject(applicant.id)}
                            as="button"
                            onBefore={() =>
                                confirm(
                                    `Reject ${applicant.name}'s application? You can approve it later from the Rejected list.`,
                                )
                            }
                            disabled={form.processing}
                            className="inline-flex h-10 items-center justify-center rounded-md border border-input px-4 text-sm font-semibold text-muted-foreground hover:bg-surface-muted hover:text-foreground sm:h-11"
                        >
                            Reject
                        </Link>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
