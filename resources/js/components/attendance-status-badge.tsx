import Icon from '@/components/icon';
import type { IconName } from '@/components/icon';
import { Badge } from '@/components/ui/badge';

export type AttendanceStatus = 'present' | 'late' | 'absent' | 'excused';

const labels: Record<AttendanceStatus, string> = {
    present: 'On time',
    late: 'Late',
    absent: 'Absent',
    excused: 'Excused',
};

const icons: Record<AttendanceStatus, IconName> = {
    present: 'clock',
    late: 'alarm',
    absent: 'cross',
    excused: 'info',
};

type AttendanceStatusBadgeProps = {
    status: AttendanceStatus;
    minutesLate?: number | null;
};

export default function AttendanceStatusBadge({
    status,
    minutesLate,
}: AttendanceStatusBadgeProps) {
    return (
        <Badge tone={status}>
            <Icon name={icons[status]} className="size-3.5" />
            {labels[status]}
            {status === 'late' && minutesLate ? ` · ${minutesLate} min` : null}
        </Badge>
    );
}
