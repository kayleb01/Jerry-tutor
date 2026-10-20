import type { Auth } from '@/types/auth';
import type { Toast } from '@/types/ui';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            badges: {
                classReports?: number;
                lateness?: number;
                subscriptions?: number;
            };
            billing?: { selfPay: boolean };
            /** Verified tutor applications awaiting a decision; null unless the viewer is an admin. */
            pendingTutorCount?: number | null;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: Toast;
            importErrors?: string[];
        };
    }
}
