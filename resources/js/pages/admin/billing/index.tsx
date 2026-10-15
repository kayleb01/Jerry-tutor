import { Form } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/admin/billing/plans';

type PlanRow = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    amount: string;
    maxStudents: number;
    paystackPlanCode: string | null;
    flutterwavePlanId: string | null;
    isActive: boolean;
    subscribers: number;
};

type Gateway = {
    value: 'paystack' | 'flutterwave';
    label: string;
    isDefault: boolean;
    linkedPlans: number;
    hasKeys: boolean;
};

type BillingSettingsProps = {
    plans: PlanRow[];
    gateways: Gateway[];
};

const hint = 'text-[0.8125rem] text-muted-foreground';

export default function BillingSettings({
    plans,
    gateways,
}: BillingSettingsProps) {
    return (
        <AppLayout title="Billing settings" subtitle="Payments">
            <p className="-mt-2 text-body text-muted-foreground sm:-mt-3">
                Create each monthly plan in the Paystack and Flutterwave
                dashboards (in naira), then paste its code here. The amounts
                must match.
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                {gateways.map((gateway) => {
                    const ready = gateway.hasKeys && gateway.linkedPlans > 0;

                    return (
                        <Card
                            key={gateway.value}
                            className="flex items-start justify-between gap-3 p-card"
                        >
                            <div>
                                <p className="font-semibold">
                                    {gateway.label}
                                    {gateway.isDefault && (
                                        <span className="ml-2 text-[0.8125rem] font-normal text-muted-foreground">
                                            used for new subscriptions
                                        </span>
                                    )}
                                </p>
                                <p className={hint}>
                                    {gateway.hasKeys
                                        ? `${gateway.linkedPlans} ${gateway.linkedPlans === 1 ? 'plan' : 'plans'} linked`
                                        : 'API keys missing from the server settings'}
                                </p>
                            </div>
                            <Badge tone={ready ? 'present' : 'late'}>
                                {ready ? 'Ready' : 'Not ready'}
                            </Badge>
                        </Card>
                    );
                })}
            </div>

            {plans.map((plan) => (
                <Card key={plan.id} className="p-card">
                    <Form
                        {...update.form(plan.id)}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h2 className="text-section font-bold">
                                        {plan.name}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {plan.amount} / month ·{' '}
                                        {plan.subscribers}{' '}
                                        {plan.subscribers === 1
                                            ? 'subscriber'
                                            : 'subscribers'}
                                    </p>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor={`name-${plan.id}`}>
                                            Name
                                        </Label>
                                        <Input
                                            id={`name-${plan.id}`}
                                            name="name"
                                            defaultValue={plan.name}
                                            required
                                            aria-invalid={!!errors.name}
                                            aria-describedby={`name-${plan.id}-error`}
                                        />
                                        <FieldError
                                            id={`name-${plan.id}-error`}
                                            message={errors.name}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label
                                            htmlFor={`description-${plan.id}`}
                                        >
                                            Short description
                                        </Label>
                                        <Input
                                            id={`description-${plan.id}`}
                                            name="description"
                                            defaultValue={
                                                plan.description ?? ''
                                            }
                                            aria-invalid={!!errors.description}
                                            aria-describedby={`description-${plan.id}-error`}
                                        />
                                        <FieldError
                                            id={`description-${plan.id}-error`}
                                            message={errors.description}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor={`paystack-${plan.id}`}>
                                            Paystack plan code
                                        </Label>
                                        <Input
                                            id={`paystack-${plan.id}`}
                                            name="paystack_plan_code"
                                            defaultValue={
                                                plan.paystackPlanCode ?? ''
                                            }
                                            placeholder="PLN_…"
                                            aria-invalid={
                                                !!errors.paystack_plan_code
                                            }
                                            aria-describedby={`paystack-${plan.id}-error`}
                                        />
                                        <FieldError
                                            id={`paystack-${plan.id}-error`}
                                            message={errors.paystack_plan_code}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label
                                            htmlFor={`flutterwave-${plan.id}`}
                                        >
                                            Flutterwave payment plan id
                                        </Label>
                                        <Input
                                            id={`flutterwave-${plan.id}`}
                                            name="flutterwave_plan_id"
                                            defaultValue={
                                                plan.flutterwavePlanId ?? ''
                                            }
                                            inputMode="numeric"
                                            aria-invalid={
                                                !!errors.flutterwave_plan_id
                                            }
                                            aria-describedby={`flutterwave-${plan.id}-error`}
                                        />
                                        <FieldError
                                            id={`flutterwave-${plan.id}-error`}
                                            message={errors.flutterwave_plan_id}
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor={`max-${plan.id}`}>
                                            Children covered
                                        </Label>
                                        {plan.slug === 'standard' ? (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="max_students"
                                                    value={1}
                                                />
                                                <p
                                                    id={`max-${plan.id}`}
                                                    className="flex h-10 items-center text-sm"
                                                >
                                                    One child
                                                </p>
                                            </>
                                        ) : (
                                            <Input
                                                id={`max-${plan.id}`}
                                                name="max_students"
                                                type="number"
                                                min={1}
                                                max={10}
                                                defaultValue={plan.maxStudents}
                                                aria-invalid={
                                                    !!errors.max_students
                                                }
                                                aria-describedby={`max-${plan.id}-error`}
                                            />
                                        )}
                                        <FieldError
                                            id={`max-${plan.id}-error`}
                                            message={errors.max_students}
                                        />
                                    </div>
                                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="is_active"
                                            value="1"
                                            defaultChecked={plan.isActive}
                                            className="size-4 accent-primary"
                                        />
                                        Offer this plan to parents
                                    </label>
                                </div>
                                <p className={hint}>
                                    The price is set in each dashboard. To
                                    change it, create a new plan there and paste
                                    the new code; current subscribers stay on
                                    their old price.
                                </p>
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        Save {plan.name}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Card>
            ))}
        </AppLayout>
    );
}
