import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import type { Option } from '@/types';

export type ClassGroupDefaults = {
    name?: string;
    yearGroup?: number | null;
    isActive?: boolean;
};

type ClassGroupFieldsProps = {
    errors: Record<string, string | undefined>;
    yearGroups: Option[];
    defaults?: ClassGroupDefaults;
};

export default function ClassGroupFields({
    errors,
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
                    placeholder="e.g. Year 5"
                    required
                    aria-invalid={!!errors.name}
                    aria-describedby="name-error"
                />
                <FieldError id="name-error" message={errors.name} />
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
                <FieldError id="year_group-error" message={errors.year_group} />
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
