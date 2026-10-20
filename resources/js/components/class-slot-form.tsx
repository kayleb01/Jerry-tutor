import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import type { Option } from '@/types';

export type ClassSlotRow = {
    id: number;
    label: string;
    subject: string;
    subjectId: number;
    tutor: string | null;
    tutorId: number | null;
    weekday: number | null;
    startTime: string | null;
    duration: number;
    location: string | null;
};

type ClassSlotFormProps = {
    action: { action: string; method: 'post' | 'put' };
    idPrefix: string;
    subjects: Option[];
    tutors: Option[];
    weekdays: Option[];
    slot?: ClassSlotRow;
    submitLabel: string;
    onDone?: () => void;
};

export default function ClassSlotForm({
    action,
    idPrefix,
    subjects,
    tutors,
    weekdays,
    slot,
    submitLabel,
    onDone,
}: ClassSlotFormProps) {
    const id = (name: string) => `${idPrefix}-${name}`;
    const subjectOptions =
        slot && !subjects.some((option) => option.value === slot.subjectId)
            ? [...subjects, { value: slot.subjectId, label: slot.subject }]
            : subjects;

    const tutorOptions =
        slot?.tutorId != null &&
        !tutors.some((option) => option.value === slot.tutorId)
            ? [
                  ...tutors,
                  { value: slot.tutorId, label: slot.tutor ?? 'Current tutor' },
              ]
            : tutors;

    return (
        <Form
            {...action}
            resetOnSuccess={!slot}
            onSuccess={onDone}
            className="grid gap-4 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={id('subject_id')}>Subject</Label>
                        <Select
                            id={id('subject_id')}
                            name="subject_id"
                            defaultValue={slot?.subjectId ?? ''}
                            required
                            aria-invalid={!!errors.subject_id}
                            aria-describedby={id('subject_id-error')}
                        >
                            <option value="" disabled>
                                Choose a subject
                            </option>
                            {subjectOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <FieldError
                            id={id('subject_id-error')}
                            message={errors.subject_id}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={id('tutor_id')}>Tutor</Label>
                        <Select
                            id={id('tutor_id')}
                            name="tutor_id"
                            defaultValue={slot?.tutorId ?? ''}
                            aria-invalid={!!errors.tutor_id}
                            aria-describedby={id('tutor_id-error')}
                        >
                            <option value="">No tutor yet</option>
                            {tutorOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <FieldError
                            id={id('tutor_id-error')}
                            message={errors.tutor_id}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={id('weekday')}>Day</Label>
                        <Select
                            id={id('weekday')}
                            name="weekday"
                            defaultValue={slot?.weekday ?? ''}
                            aria-invalid={!!errors.weekday}
                            aria-describedby={id('weekday-error')}
                        >
                            <option value="">No regular time</option>
                            {weekdays.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <FieldError
                            id={id('weekday-error')}
                            message={errors.weekday}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={id('start_time')}>Start time</Label>
                        <Input
                            id={id('start_time')}
                            name="start_time"
                            type="time"
                            defaultValue={slot?.startTime ?? ''}
                            aria-invalid={!!errors.start_time}
                            aria-describedby={id('start_time-error')}
                        />
                        <FieldError
                            id={id('start_time-error')}
                            message={errors.start_time}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={id('duration_minutes')}>
                            Length (minutes)
                        </Label>
                        <Input
                            id={id('duration_minutes')}
                            name="duration_minutes"
                            type="number"
                            min={15}
                            max={240}
                            step={5}
                            defaultValue={slot?.duration ?? 60}
                            required
                            aria-invalid={!!errors.duration_minutes}
                            aria-describedby={id('duration_minutes-error')}
                        />
                        <FieldError
                            id={id('duration_minutes-error')}
                            message={errors.duration_minutes}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={id('location')}>Room (optional)</Label>
                        <Input
                            id={id('location')}
                            name="location"
                            defaultValue={slot?.location ?? ''}
                            placeholder="e.g. Room 2"
                            aria-invalid={!!errors.location}
                            aria-describedby={id('location-error')}
                        />
                        <FieldError
                            id={id('location-error')}
                            message={errors.location}
                        />
                    </div>
                    <div className="flex flex-wrap gap-3 sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                        {onDone && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onDone}
                            >
                                Cancel
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}
