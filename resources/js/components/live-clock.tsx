import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

type LiveClockProps = {
    /** The academy's timezone, so the clock matches the times the app records. */
    timezone: string;
    /** When set ("HH:MM"), the clock stops and shows this time instead. */
    frozenAt?: string | null;
    className?: string;
};

function formatTime(date: Date, timezone: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(date);
}

/** A large ticking HH:MM:SS clock in the academy's time, or a frozen stamp once checked in. */
export default function LiveClock({
    timezone,
    frozenAt,
    className,
}: LiveClockProps) {
    // Empty until mounted so the server render and the first client render match.
    const [now, setNow] = useState<string | null>(null);

    useEffect(() => {
        if (frozenAt) {
            return;
        }

        const tick = () => setNow(formatTime(new Date(), timezone));
        tick();
        const timer = setInterval(tick, 1000);

        return () => clearInterval(timer);
    }, [timezone, frozenAt]);

    return (
        <time
            className={cn(
                'block text-center font-mono text-5xl font-bold tracking-tight tabular-nums sm:text-6xl',
                frozenAt ? 'text-present' : 'text-foreground',
                className,
            )}
            aria-live="off"
        >
            {frozenAt ?? now ?? '--:--:--'}
        </time>
    );
}
