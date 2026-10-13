import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import type { Option } from '@/types';

export type ClassGroupDefaults = {
    name?: string;
    tutorId?: number | null;
    yearGroup?: number | null;
    location?: string | null;
    weekday?: number | null;
    startTime?: string | null;
    durationMinutes?: number;
    isActive?: boolean;
};

type ClassGroupFieldsProps = {
    errors: Record<string, string | undefined>;
    tutors: Option[];
    weekdays: Option[];
    yearGroups: Option[];
    defaults?: ClassGroupDefaults;
};

export default function ClassGroupFields({
    errors,
    tutors,
    weekdays,
    yearGroups,
    defaults = {},
}: ClassGroupFieldsProps) {
    return (
        <>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="name">Class name</Label>
                <Input
                    id="name"
                    name="name"
                    defaultValue={defaults.name}
                    placeholder="e.g. Year 5 Maths – Saturday"
                    required
                    aria-invalid={!!errors.name}
                    aria-describedby="name-error"
                />
                <FieldError id="name-error" message={errors.name} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="tutor_id">Tutor</Label>
                    <Select
                        id="tutor_id"
                        name="tutor_id"
                        defaultValue={defaults.tutorId ?? ''}
                        aria-invalid={!!errors.tutor_id}
                        aria-describedby="tutor_id-error"
                    >
                        <option value="">No tutor yet</option>
                        {tutors.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <FieldError id="tutor_id-error" message={errors.tutor_id} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="year_group">Year group (optional)</Label>
                    <Select
                        id="year_group"
                        name="year_group"
                        defaultValue={defaults.yearGroup ?? ''}
                        aria-invalid={!!errors.year_group}
                        aria-describedby="year_group-error"
                    >
                        <option value="">Mixed / not set</option>
                        {yearGroups.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <FieldError
                        id="year_group-error"
                        message={errors.year_group}
                    />
                </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="weekday">Day</Label>
                    <Select
                        id="weekday"
                        name="weekday"
                        defaultValue={defaults.weekday ?? ''}
                        aria-invalid={!!errors.weekday}
                        aria-describedby="weekday-error"
                    >
                        <option value="">No regular day</option>
                        {weekdays.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <FieldError id="weekday-error" message={errors.weekday} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="start_time">Start time</Label>
                    <Input
                        id="start_time"
                        name="start_time"
                        type="time"
                        defaultValue={defaults.startTime ?? ''}
                        aria-invalid={!!errors.start_time}
                        aria-describedby="start_time-error"
                    />
                    <FieldError
                        id="start_time-error"
                        message={errors.start_time}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="duration_minutes">Length (minutes)</Label>
                    <Input
                        id="duration_minutes"
                        name="duration_minutes"
                        type="number"
                        min={15}
                        max={240}
                        step={5}
                        defaultValue={defaults.durationMinutes ?? 60}
                        required
                        aria-invalid={!!errors.duration_minutes}
                        aria-describedby="duration_minutes-error"
                    />
                    <FieldError
                        id="duration_minutes-error"
                        message={errors.duration_minutes}
                    />
                </div>
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="location">Room or link (optional)</Label>
                <Input
                    id="location"
                    name="location"
                    defaultValue={defaults.location ?? ''}
                    aria-invalid={!!errors.location}
                    aria-describedby="location-error"
                />
                <FieldError id="location-error" message={errors.location} />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0" />
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    defaultChecked={defaults.isActive ?? true}
                    className="size-4 accent-primary"
                />
                Active (lessons are scheduled)
            </label>
        </>
    );
}
