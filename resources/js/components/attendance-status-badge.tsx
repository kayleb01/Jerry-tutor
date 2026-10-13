import { Badge } from '@/components/ui/badge';

export type AttendanceStatus = 'present' | 'late' | 'absent' | 'excused';

const labels: Record<AttendanceStatus, string> = {
    present: 'On time',
    late: 'Late',
    absent: 'Absent',
    excused: 'Excused',
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
            {labels[status]}
            {status === 'late' && minutesLate ? ` · ${minutesLate} min` : null}
        </Badge>
    );
}
