import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export default function Toast() {
    const { flash } = usePage();

    if (!flash.toast) {
        return null;
    }

    return (
        <div
            role="status"
            className={cn(
                'rounded-md px-4 py-3 text-sm font-medium',
                flash.toast.type === 'success'
                    ? 'bg-present-soft text-present'
                    : 'bg-absent-soft text-absent',
            )}
        >
            {flash.toast.message}
        </div>
    );
}
