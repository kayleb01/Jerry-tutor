import { usePage } from '@inertiajs/react';
import Icon from '@/components/icon';
import { cn } from '@/lib/utils';

export default function Toast() {
    const { flash } = usePage();

    if (!flash.toast) {
        return null;
    }

    const success = flash.toast.type === 'success';

    return (
        <div
            role="status"
            className={cn(
                'flex items-start gap-3 rounded-md px-4 py-3.5 text-sm font-medium',
                success
                    ? 'bg-primary-95 text-primary-10 dark:bg-primary-20 dark:text-primary-95'
                    : 'bg-absent-soft text-absent',
            )}
        >
            <Icon name={success ? 'check' : 'info'} />
            {flash.toast.message}
        </div>
    );
}
