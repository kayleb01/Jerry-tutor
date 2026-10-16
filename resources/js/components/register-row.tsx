import { useState } from 'react';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import PersonAvatar from '@/components/person-avatar';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type RegisterPerson = {
    id: number;
    name: string;
    yearGroup: string | null;
    status: string | null;
    arrivedAt: string | null;
    minutesLate: number | null;
    notes: string | null;
    /** Whether marking them absent alerts a parent. */
    hasParent: boolean;
};

export type Mark = { status: string; arrived: string };

const buttons: { value: string; label: string; icon: IconName }[] = [
    { value: 'present', label: 'On time', icon: 'clock' },
    { value: 'late', label: 'Late', icon: 'alarm' },
    { value: 'absent', label: 'Absent', icon: 'cross' },
];

const checkedTone: Record<string, string> = {
    present:
        'has-checked:border-present-soft has-checked:bg-present-soft has-checked:text-present',
    late: 'has-checked:border-late-soft has-checked:bg-late-soft has-checked:text-late',
    absent: 'has-checked:border-danger has-checked:bg-danger has-checked:text-white dark:has-checked:text-neutral-10',
};

/** Minutes from one "HH:MM" to another. */
export function minutesBetween(from: string, to: string): number {
    const toMinutes = (time: string) => {
        const [hours, minutes] = time.split(':').map(Number);

        return hours * 60 + minutes;
    };

    return toMinutes(to) - toMinutes(from);
}

/** What the mark will be saved as: a late arrival inside the grace period counts as on time. */
export function effectiveStatus(
    mark: Mark,
    startTime: string,
    graceMinutes: number,
): string {
    if (mark.status === 'late' && mark.arrived) {
        return minutesBetween(startTime, mark.arrived) > graceMinutes
            ? 'late'
            : 'present';
    }

    return mark.status;
}

type RegisterRowProps = {
    person: RegisterPerson;
    role: 'student' | 'tutor';
    mark: Mark;
    onChange: (mark: Mark) => void;
    startTime: string;
    graceMinutes: number;
    errors: Record<string, string | undefined>;
    /** Looking only: the buttons and arrival time are disabled and notes can't be added. */
    readOnly?: boolean;
};

export default function RegisterRow({
    person,
    role,
    mark,
    onChange,
    startTime,
    graceMinutes,
    errors,
    readOnly = false,
}: RegisterRowProps) {
    const [isNoteOpen, setIsNoteOpen] = useState(Boolean(person.notes));
    const field = (name: string) => `attendance[${person.id}][${name}]`;
    const errorKey = (name: string) => `attendance.${person.id}.${name}`;
    const came = mark.status === 'present' || mark.status === 'late';

    const choose = (status: string) =>
        onChange({
            status,
            arrived:
                mark.arrived ||
                (person.status === 'present' || person.status === 'late'
                    ? (person.arrivedAt ?? startTime)
                    : startTime),
        });

    const setArrived = (arrived: string) =>
        onChange({
            arrived,
            // An "on time" arrival after the grace period is a late one.
            status:
                mark.status === 'present' &&
                arrived &&
                minutesBetween(startTime, arrived) > graceMinutes
                    ? 'late'
                    : mark.status,
        });

    return (
        <fieldset className="flex flex-col gap-3 border-b py-3 last:border-0">
            <legend className="sr-only">{person.name}</legend>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                <div className="flex min-w-48 flex-1 items-center gap-3">
                    <PersonAvatar name={person.name} role={role} />
                    <div className="min-w-0">
                        <p className="truncate font-semibold">{person.name}</p>
                        <p
                            className="text-[0.8125rem] text-muted-foreground"
                            aria-live="polite"
                        >
                            {statusLine(
                                person,
                                role,
                                mark,
                                startTime,
                                graceMinutes,
                            )}
                        </p>
                    </div>
                </div>
                <div
                    className="flex flex-wrap items-center gap-2"
                    role="radiogroup"
                    aria-label={`Attendance for ${person.name}`}
                >
                    {buttons.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'inline-flex h-10 cursor-pointer items-center gap-2 rounded-md border border-neutral-80 bg-surface px-3 text-[0.8125rem] font-semibold text-muted-foreground has-focus-visible:outline-2 has-focus-visible:outline-ring has-disabled:cursor-default dark:border-neutral-30',
                                checkedTone[option.value],
                            )}
                        >
                            <input
                                type="radio"
                                name={field('status')}
                                value={option.value}
                                checked={mark.status === option.value}
                                disabled={readOnly}
                                onChange={() => choose(option.value)}
                                className="sr-only"
                            />
                            <Icon name={option.icon} className="size-4" />
                            {option.label}
                        </label>
                    ))}
                </div>
                <div className="flex w-48 items-center gap-2">
                    {came && (
                        <>
                            <label
                                htmlFor={`${person.id}-arrived`}
                                className="text-[0.8125rem] text-muted-foreground"
                            >
                                Arrived
                            </label>
                            <Input
                                id={`${person.id}-arrived`}
                                name={field('arrived_at')}
                                type="time"
                                value={mark.arrived}
                                onChange={(event) =>
                                    setArrived(event.target.value)
                                }
                                disabled={readOnly}
                                required={mark.status === 'late'}
                                aria-invalid={!!errors[errorKey('arrived_at')]}
                                aria-describedby={`${person.id}-arrived-error`}
                                className="h-10 min-w-0 flex-1 px-2 sm:h-10 sm:px-2"
                            />
                        </>
                    )}
                </div>
            </div>
            <FieldError
                id={`${person.id}-status-error`}
                message={errors[errorKey('status')]}
            />
            <FieldError
                id={`${person.id}-arrived-error`}
                message={errors[errorKey('arrived_at')]}
            />
            {mark.status !== '' &&
                (isNoteOpen || (readOnly && person.notes) ? (
                    <div className="flex flex-col gap-1.5 sm:pl-12">
                        <label
                            htmlFor={`${person.id}-notes`}
                            className="text-sm font-medium text-muted-foreground"
                        >
                            Note
                        </label>
                        <Input
                            id={`${person.id}-notes`}
                            name={field('notes')}
                            defaultValue={person.notes ?? ''}
                            readOnly={readOnly}
                            autoFocus={!person.notes}
                            aria-describedby={`${person.id}-notes-error`}
                        />
                        <FieldError
                            id={`${person.id}-notes-error`}
                            message={errors[errorKey('notes')]}
                        />
                    </div>
                ) : readOnly ? null : (
                    <button
                        type="button"
                        onClick={() => setIsNoteOpen(true)}
                        className="self-start text-[0.8125rem] font-semibold text-link hover:underline sm:ml-12"
                    >
                        Add note
                    </button>
                ))}
        </fieldset>
    );
}

function statusLine(
    person: RegisterPerson,
    role: 'student' | 'tutor',
    mark: Mark,
    startTime: string,
    graceMinutes: number,
): string {
    if (mark.status === '') {
        return person.status === 'excused' ? 'Excused' : 'Not marked yet';
    }

    if (mark.status === 'absent') {
        return role === 'student' &&
            person.hasParent &&
            person.status !== 'absent'
            ? 'Absent · parent will be notified'
            : 'Absent';
    }

    if (effectiveStatus(mark, startTime, graceMinutes) === 'late') {
        const minutes = minutesBetween(startTime, mark.arrived);

        return person.status === 'late'
            ? `Late · ${minutes} min`
            : `Late · ${minutes} min · report will be created`;
    }

    return mark.status === 'late'
        ? 'Within the grace period · saved as on time'
        : 'On time';
}
