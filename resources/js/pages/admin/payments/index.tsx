import { Form, Link } from '@inertiajs/react';
import Icon from '@/components/icon';
import PaymentsTable from '@/components/payments-table';
import type { PaymentRow } from '@/components/payments-table';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { exportMethod as exportCsv, index } from '@/routes/admin/payments';
import { index as subscriptionsIndex } from '@/routes/admin/subscriptions';
import type { Option, Paginated } from '@/types';

type Filters = {
    status: string | null;
    gateway: string | null;
    plan: number | null;
    search: string;
    from: string | null;
    to: string | null;
};

type PaymentsProps = {
    payments: Paginated<PaymentRow>;
    filters: Filters;
    statuses: Option[];
    gateways: Option[];
    plans: Option[];
};

/** The filters as a query string, without empty values. */
function query(filters: Filters) {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );
}

export default function Payments({
    payments,
    filters,
    statuses,
    gateways,
    plans,
}: PaymentsProps) {
    const isFiltered = Object.keys(query(filters)).length > 0;

    return (
        <AppLayout
            title="All payments"
            subtitle="Payments"
            actions={
                <a
                    href={exportCsv.url({ query: query(filters) })}
                    className={buttonClasses('primary')}
                >
                    <Icon name="download" className="size-4" />
                    Payments report
                </a>
            }
        >
            <Link
                href={subscriptionsIndex()}
                className="-mt-2 inline-flex items-center gap-1.5 self-start text-sm font-semibold text-link hover:underline sm:-mt-3"
            >
                <Icon name="arrow-left" className="size-4" />
                Subscriptions
            </Link>

            <Card className="p-card">
                <Form
                    {...index.form()}
                    className="grid grid-cols-2 items-end gap-3 sm:grid-cols-3 lg:flex lg:flex-wrap"
                    aria-label="Filter payments"
                >
                    <div className="col-span-2 flex flex-col gap-1.5 sm:col-span-3 lg:w-64">
                        <Label htmlFor="search">Parent</Label>
                        <Input
                            id="search"
                            name="search"
                            type="search"
                            defaultValue={filters.search}
                            placeholder="Name or email"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5 lg:w-36">
                        <Label htmlFor="status">Status</Label>
                        <Select
                            id="status"
                            name="status"
                            defaultValue={filters.status ?? ''}
                        >
                            <option value="">All</option>
                            {statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 lg:w-48">
                        <Label htmlFor="plan">Plan</Label>
                        <Select
                            id="plan"
                            name="plan"
                            defaultValue={filters.plan ?? ''}
                        >
                            <option value="">All plans</option>
                            {plans.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 lg:w-40">
                        <Label htmlFor="gateway">Gateway</Label>
                        <Select
                            id="gateway"
                            name="gateway"
                            defaultValue={filters.gateway ?? ''}
                        >
                            <option value="">Both</option>
                            {gateways.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 lg:w-40">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            name="from"
                            type="date"
                            defaultValue={filters.from ?? ''}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5 lg:w-40">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            name="to"
                            type="date"
                            defaultValue={filters.to ?? ''}
                        />
                    </div>
                    <div className="col-span-2 flex items-center gap-3 sm:col-span-1">
                        <Button type="submit" variant="outline">
                            <Icon name="filter" className="size-4" />
                            Apply
                        </Button>
                        {isFiltered && (
                            <Link
                                href={index()}
                                className="text-sm font-semibold text-link hover:underline"
                            >
                                Reset
                            </Link>
                        )}
                    </div>
                </Form>
            </Card>

            <Card className="p-card">
                {payments.data.length === 0 ? (
                    <p className="py-4 text-sm text-muted-foreground">
                        {isFiltered
                            ? 'No payments match these filters.'
                            : 'No payments yet.'}
                    </p>
                ) : (
                    <PaymentsTable payments={payments.data} showReason />
                )}
            </Card>

            <nav
                className="flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <span className="text-muted-foreground">
                    {payments.total}{' '}
                    {payments.total === 1 ? 'payment' : 'payments'} · page{' '}
                    {payments.current_page} of {payments.last_page}
                </span>
                <div className="flex gap-3">
                    {payments.prev_page_url && (
                        <Link
                            href={payments.prev_page_url}
                            className="font-medium text-link"
                        >
                            Previous
                        </Link>
                    )}
                    {payments.next_page_url && (
                        <Link
                            href={payments.next_page_url}
                            className="font-medium text-link"
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </AppLayout>
    );
}
