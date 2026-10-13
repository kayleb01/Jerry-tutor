import { useState } from 'react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import type { Option } from '@/types';

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
                    <p className="font-medium">{person.name}</p>
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
                        className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-md border border-input px-3 text-sm has-checked:border-primary has-checked:bg-royal-50 dark:has-checked:bg-royal-900/40"
                    >
                        <input
                            type="radio"
                            name={field('status')}
                            value={option.value}
                            checked={status === option.value}
                            onChange={() => setStatus(String(option.value))}
                            className="accent-primary"
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
