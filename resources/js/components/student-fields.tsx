import FieldError from '@/components/field-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import type { Option } from '@/types';

type StudentFieldsProps = {
    errors: Record<string, string | undefined>;
    yearGroups: Option[];
    defaults?: {
        name?: string;
        username?: string | null;
        email?: string | null;
        yearGroup?: number | null;
    };
};

export default function StudentFields({
    errors,
    yearGroups,
    defaults = {},
}: StudentFieldsProps) {
    return (
        <>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="name">Full name</Label>
                <Input
                    id="name"
                    name="name"
                    defaultValue={defaults.name}
                    required
                    aria-invalid={!!errors.name}
                    aria-describedby="name-error"
                />
                <FieldError id="name-error" message={errors.name} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="username">Username</Label>
                <Input
                    id="username"
                    name="username"
                    defaultValue={defaults.username ?? ''}
                    autoComplete="off"
                    required
                    aria-invalid={!!errors.username}
                    aria-describedby="username-hint username-error"
                />
                <p id="username-hint" className="text-xs text-muted-foreground">
                    Lowercase letters, numbers, dots, dashes or underscores, for
                    example ava.okafor
                </p>
                <FieldError id="username-error" message={errors.username} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="email">Email (optional)</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    defaultValue={defaults.email ?? ''}
                    aria-invalid={!!errors.email}
                    aria-describedby="email-error"
                />
                <FieldError id="email-error" message={errors.email} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="year_group">Year group</Label>
                <Select
                    id="year_group"
                    name="year_group"
                    defaultValue={defaults.yearGroup ?? ''}
                    required
                    aria-invalid={!!errors.year_group}
                    aria-describedby="year_group-error"
                >
                    <option value="" disabled>
                        Choose a year group
                    </option>
                    {yearGroups.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>
                <FieldError id="year_group-error" message={errors.year_group} />
            </div>
        </>
    );
}
