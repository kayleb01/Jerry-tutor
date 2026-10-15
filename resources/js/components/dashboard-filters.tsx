import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { dashboard } from '@/routes';
import type { Option } from '@/types';

export type DashboardFilterValues = {
    period: string;
    from: string;
    to: string;
    class: number | null;
    tutor: number | null;
    role: 'student' | 'tutor' | null;
    label: string;
    range: string;
    error: string | null;
};

export type DashboardFilterOptions = {
    periods: Option[];
    classes: Option[];
    tutors: Option[];
};

type DashboardFiltersProps = {
    filters: DashboardFilterValues;
    options: DashboardFilterOptions;
};

export default function DashboardFilters({
    filters,
    options,
}: DashboardFiltersProps) {
    const [period, setPeriod] = useState(filters.period);
    const isFiltered =
        filters.period !== 'week' ||
        filters.class !== null ||
        filters.tutor !== null ||
        filters.role !== null;

    return (
        <Card className="p-card">
            <Form
                {...dashboard.form()}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-3"
                aria-label="Filter the dashboard"
            >
                <div className="grid grid-cols-2 items-end gap-3 sm:grid-cols-3 lg:flex lg:flex-wrap">
                    <div className="col-span-2 flex flex-col gap-1.5 sm:col-span-1">
                        <Label htmlFor="period">Period</Label>
                        <Select
                            id="period"
                            name="period"
                            value={period}
                            onChange={(event) => setPeriod(event.target.value)}
                            className="lg:w-44"
                        >
                            {options.periods.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    {period === 'custom' && (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="from">From</Label>
                                <Input
                                    id="from"
                                    name="from"
                                    type="date"
                                    defaultValue={filters.from}
                                    max={filters.to}
                                    required
                                    aria-describedby="period-error"
                                    className="lg:w-40"
                                />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="to">To</Label>
                                <Input
                                    id="to"
                                    name="to"
                                    type="date"
                                    defaultValue={filters.to}
                                    required
                                    aria-describedby="period-error"
                                    className="lg:w-40"
                                />
                            </div>
                        </>
                    )}
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="class">Class</Label>
                        <Select
                            id="class"
                            name="class"
                            defaultValue={filters.class ?? ''}
                            className="lg:w-48"
                        >
                            <option value="">All classes</option>
                            {options.classes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="tutor">Tutor</Label>
                        <Select
                            id="tutor"
                            name="tutor"
                            defaultValue={filters.tutor ?? ''}
                            className="lg:w-44"
                        >
                            <option value="">All tutors</option>
                            {options.tutors.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="role">Who</Label>
                        <Select
                            id="role"
                            name="role"
                            defaultValue={filters.role ?? ''}
                            className="lg:w-36"
                        >
                            <option value="">Everyone</option>
                            <option value="student">Students</option>
                            <option value="tutor">Tutors</option>
                        </Select>
                    </div>
                    <div className="col-span-2 flex items-center gap-3 sm:col-span-1">
                        <Button type="submit">
                            <Icon name="filter" className="size-4" />
                            Apply
                        </Button>
                        {isFiltered && (
                            <Link
                                href={dashboard()}
                                className="text-sm font-semibold text-link hover:underline"
                            >
                                Reset
                            </Link>
                        )}
                    </div>
                </div>
                <FieldError
                    id="period-error"
                    message={filters.error ?? undefined}
                />
                <p
                    className="text-xs text-muted-foreground sm:text-[0.8125rem]"
                    aria-live="polite"
                >
                    Showing{' '}
                    <span className="font-semibold text-foreground">
                        {filters.label}
                    </span>
                    {filters.period === 'custom' ? '' : ` (${filters.range})`}.
                    Today’s classes always show today.
                </p>
            </Form>
        </Card>
    );
}
