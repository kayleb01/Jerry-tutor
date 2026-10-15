import Icon from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type PaymentRow = {
    id: number;
    date: string;
    payer: string;
    payerEmail: string | null;
    plan: string | null;
    amount: string;
    method: string;
    status: 'pending' | 'succeeded' | 'failed' | 'refunded';
    statusLabel: string;
    failureReason: string | null;
    gateway: string;
};

const statusIcons: Record<PaymentRow['status'], 'check' | 'cross' | 'clock'> = {
    succeeded: 'check',
    failed: 'cross',
    pending: 'clock',
    refunded: 'clock',
};

/** Payments with date, payer, plan, amount, card and status; optionally the email and failure reason. */
export default function PaymentsTable({
    payments,
    showReason = false,
}: {
    payments: PaymentRow[];
    showReason?: boolean;
}) {
    return (
        <div className="relative -mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
            <table className="w-full min-w-[640px] text-left text-sm">
                <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                    <tr>
                        <th scope="col" className="py-3 pr-4 font-semibold">
                            Date
                        </th>
                        <th scope="col" className="py-3 pr-4 font-semibold">
                            Parent
                        </th>
                        <th scope="col" className="py-3 pr-4 font-semibold">
                            Plan
                        </th>
                        <th scope="col" className="py-3 pr-4 font-semibold">
                            Amount
                        </th>
                        <th scope="col" className="py-3 pr-4 font-semibold">
                            Method
                        </th>
                        <th scope="col" className="py-3 font-semibold">
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {payments.map((payment) => (
                        <tr
                            key={payment.id}
                            className="border-b align-top last:border-0"
                        >
                            <td className="py-3 pr-4 whitespace-nowrap">
                                {payment.date}
                            </td>
                            <td className="py-3 pr-4">
                                {payment.payer}
                                {showReason && payment.payerEmail && (
                                    <span className="block text-[0.8125rem] text-muted-foreground">
                                        {payment.payerEmail}
                                    </span>
                                )}
                            </td>
                            <td className="py-3 pr-4">{payment.plan ?? '—'}</td>
                            <td className="py-3 pr-4 tabular-nums">
                                {payment.amount}
                            </td>
                            <td className="py-3 pr-4 text-muted-foreground">
                                {payment.method}
                            </td>
                            <td className="py-3">
                                <Badge
                                    tone={
                                        payment.status === 'succeeded'
                                            ? 'brand'
                                            : payment.status === 'failed'
                                              ? 'absent'
                                              : 'neutral'
                                    }
                                    className={cn(
                                        'gap-1',
                                        payment.status === 'failed' &&
                                            'bg-danger text-white dark:text-neutral-10',
                                    )}
                                >
                                    <Icon
                                        name={statusIcons[payment.status]}
                                        className="size-3.5"
                                    />
                                    {payment.statusLabel}
                                </Badge>
                                {showReason && payment.failureReason && (
                                    <span className="mt-1 block text-[0.8125rem] text-muted-foreground">
                                        {payment.failureReason}
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
