import { useState } from 'react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { Option } from '@/types';

const checkedTone: Record<string, string> = {
    present:
        'has-checked:border-present-soft has-checked:bg-present-soft has-checked:text-present',
    late: 'has-checked:border-late-soft has-checked:bg-late-soft has-checked:text-late',
    absent: 'has-checked:border-danger has-checked:bg-danger has-checked:text-white dark:has-checked:text-neutral-10',
    excused:
        'has-checked:border-excused-soft has-checked:bg-excused-soft has-checked:text-excused',
};

export type RegisterPerson = {
    id: number;
    name: string;
    yearGroup: string | null;
    status: string | null;
    arrivedAt: string | null;
    minutesLate: number | null;
    notes: string | null;
};

type RegisterRowProps = {
    person: RegisterPerson;
    statuses: Option[];
    errors: Record<string, string | undefined>;
    defaultTime: string;
};

export default function RegisterRow({
    person,
    statuses,
    errors,
    defaultTime,
}: RegisterRowProps) {
    const [status, setStatus] = useState(person.status ?? '');
    const field = (name: string) => `attendance[${person.id}][${name}]`;
    const errorKey = (name: string) => `attendance.${person.id}.${name}`;

    return (
        <fieldset className="flex flex-col gap-3 border-b py-4 last:border-0">
            <legend className="sr-only">{person.name}</legend>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-semibold">{person.name}</p>
                    {person.yearGroup && (
                        <p className="text-xs text-muted-foreground">
                            {person.yearGroup}
                        </p>
                    )}
                </div>
                {person.status && (
                    <AttendanceStatusBadge
                        status={person.status as AttendanceStatus}
                        minutesLate={person.minutesLate}
                    />
                )}
            </div>
            <div
                className="flex flex-wrap gap-2"
                role="radiogroup"
                aria-label={`Attendance for ${person.name}`}
            >
                {statuses.map((option) => (
                    <label
                        key={option.value}
                        className={cn(
                            'inline-flex h-10 cursor-pointer items-center gap-2 rounded-md border border-neutral-80 bg-surface px-3 text-[13px] font-semibold text-muted-foreground has-focus-visible:outline-2 has-focus-visible:outline-ring dark:border-neutral-30',
                            checkedTone[String(option.value)],
                        )}
                    >
                        <input
                            type="radio"
                            name={field('status')}
                            value={option.value}
                            checked={status === option.value}
                            onChange={() => setStatus(String(option.value))}
                            className="sr-only"
                        />
                        {option.label}
                    </label>
                ))}
            </div>
            <FieldError
                id={`${person.id}-status-error`}
                message={errors[errorKey('status')]}
            />
            {status === 'late' && (
                <div className="flex max-w-48 flex-col gap-1.5">
                    <label
                        htmlFor={`${person.id}-arrived`}
                        className="text-sm font-medium"
                    >
                        Arrived at
                    </label>
                    <Input
                        id={`${person.id}-arrived`}
                        name={field('arrived_at')}
                        type="time"
                        defaultValue={person.arrivedAt ?? defaultTime}
                        required
                        aria-invalid={!!errors[errorKey('arrived_at')]}
                        aria-describedby={`${person.id}-arrived-error`}
                    />
                    <FieldError
                        id={`${person.id}-arrived-error`}
                        message={errors[errorKey('arrived_at')]}
                    />
                </div>
            )}
            <div className="flex flex-col gap-1.5">
                <label
                    htmlFor={`${person.id}-notes`}
                    className="text-sm font-medium text-muted-foreground"
                >
                    Notes (optional)
                </label>
                <Input
                    id={`${person.id}-notes`}
                    name={field('notes')}
                    defaultValue={person.notes ?? ''}
                    aria-describedby={`${person.id}-notes-error`}
                />
                <FieldError
                    id={`${person.id}-notes-error`}
                    message={errors[errorKey('notes')]}
                />
            </div>
        </fieldset>
    );
}
