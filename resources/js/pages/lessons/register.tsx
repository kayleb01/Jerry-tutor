import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import FieldError from '@/components/field-error';
import PersonAvatar from '@/components/person-avatar';
import RegisterRow, { effectiveStatus } from '@/components/register-row';
import type { Mark, RegisterPerson } from '@/components/register-row';
import { Button, buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { checkIn as checkInRoute } from '@/routes/lessons';
import { update } from '@/routes/lessons/register';
import { edit as editReport } from '@/routes/lessons/report';

type RegisterProps = {
    lesson: {
        id: number;
        className: string;
        date: string;
        dateLong: string;
        time: string;
        location: string | null;
        status: string;
    };
    tutor: RegisterPerson;
    students: RegisterPerson[];
    canEdit: boolean;
    editingNote: string | null;
    checkIn: {
        at: string | null;
        minutesLate: number | null;
        canCheckIn: boolean;
    } | null;
    canEditTutor: boolean;
    canReport: boolean;
    graceMinutes: number;
};

const smallCaps =
    'text-[0.6875rem] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs';

/** The register as stored: excused records aren't offered as a button, so they start unmarked. */
function initialMark(person: RegisterPerson): Mark {
    return {
        status:
            person.status === 'excused' || person.status === null
                ? ''
                : person.status,
        arrived: person.arrivedAt ?? '',
    };
}

export default function Register({
    lesson,
    tutor,
    students,
    canEdit,
    editingNote,
    checkIn,
    canEditTutor,
    canReport,
    graceMinutes,
}: RegisterProps) {
    const startTime = lesson.time.split('–')[0];
    const [marks, setMarks] = useState<Record<number, Mark>>(() =>
        Object.fromEntries(
            [tutor, ...students].map((person) => [
                person.id,
                initialMark(person),
            ]),
        ),
    );
    const setMark = (id: number, mark: Mark) =>
        setMarks((current) => ({ ...current, [id]: mark }));

    const saved = students.map((student) =>
        effectiveStatus(marks[student.id], startTime, graceMinutes),
    );
    const count = (...statuses: string[]) =>
        saved.filter((status) => statuses.includes(status)).length;
    const unmarked = students.filter(
        (student) => marks[student.id].status === '',
    );

    const markUnmarkedOnTime = () =>
        setMarks((current) => ({
            ...current,
            ...Object.fromEntries(
                unmarked.map((student) => [
                    student.id,
                    { status: 'present', arrived: startTime },
                ]),
            ),
        }));

    return (
        <AppLayout
            title={lesson.className}
            subtitle={`${lesson.dateLong} · scheduled ${lesson.time.replace('–', ' – ')}${lesson.location ? ` · ${lesson.location}` : ''}`}
            actions={
                <>
                    {checkIn && (
                        <CheckInBadge lessonId={lesson.id} checkIn={checkIn} />
                    )}
                    {canReport && (
                        <Link
                            href={editReport(lesson.id)}
                            className={buttonClasses('outline')}
                        >
                            Write end-of-class report
                        </Link>
                    )}
                </>
            }
        >
            <Link
                href={dashboard()}
                className="self-start text-sm font-semibold text-link hover:underline"
            >
                ← Dashboard
            </Link>
            <Form {...update.form(lesson.id)} className="flex flex-col gap-6">
                {({ errors, processing }) => (
                    <>
                        <FieldError
                            id="attendance-error"
                            message={errors.attendance}
                        />

                        <section
                            className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4"
                            aria-label="Register so far"
                            aria-live="polite"
                        >
                            <Count
                                label="Present"
                                value={count('present', 'late')}
                                hint="On time or late"
                            />
                            <Count
                                label="Late"
                                value={count('late')}
                                hint="A lateness report is created for each"
                                tone="text-late"
                            />
                            <Count
                                label="Absent"
                                value={count('absent')}
                                hint="Parents are notified"
                                tone="text-absent"
                            />
                            <Count
                                label="Not marked"
                                value={unmarked.length}
                                hint={`of ${students.length} ${students.length === 1 ? 'student' : 'students'}`}
                            />
                        </section>

                        {checkIn === null && (
                            <Card className="p-card">
                                <h2 className="mb-1 text-base font-bold sm:text-lg">
                                    Tutor
                                </h2>
                                {canEditTutor ? (
                                    <RegisterRow
                                        person={tutor}
                                        role="tutor"
                                        mark={marks[tutor.id]}
                                        onChange={(mark) =>
                                            setMark(tutor.id, mark)
                                        }
                                        startTime={startTime}
                                        graceMinutes={graceMinutes}
                                        errors={errors}
                                        readOnly={!canEdit}
                                    />
                                ) : (
                                    <div className="flex items-center justify-between gap-3 py-3">
                                        <span className="flex items-center gap-3 font-semibold">
                                            <PersonAvatar
                                                name={tutor.name}
                                                role="tutor"
                                            />
                                            {tutor.name}
                                        </span>
                                        {tutor.status ? (
                                            <AttendanceStatusBadge
                                                status={
                                                    tutor.status as AttendanceStatus
                                                }
                                                minutesLate={tutor.minutesLate}
                                            />
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                Not checked in. Check in from
                                                your Today screen.
                                            </span>
                                        )}
                                    </div>
                                )}
                            </Card>
                        )}

                        {editingNote && (
                            <p className="text-sm text-muted-foreground">
                                {editingNote}
                            </p>
                        )}

                        <Card className="p-card">
                            <div className="mb-1 flex flex-wrap items-center justify-between gap-3 border-b pb-3">
                                <h2 className="text-base font-bold sm:text-lg">
                                    Register
                                </h2>
                                {canEdit && unmarked.length > 0 && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={markUnmarkedOnTime}
                                    >
                                        Mark everyone unmarked as on time
                                    </Button>
                                )}
                            </div>
                            {students.length === 0 ? (
                                <p className="py-4 text-sm text-muted-foreground">
                                    No students are enrolled in this class.
                                </p>
                            ) : (
                                students.map((student) => (
                                    <RegisterRow
                                        key={student.id}
                                        person={student}
                                        role="student"
                                        mark={marks[student.id]}
                                        onChange={(mark) =>
                                            setMark(student.id, mark)
                                        }
                                        startTime={startTime}
                                        graceMinutes={graceMinutes}
                                        errors={errors}
                                        readOnly={!canEdit}
                                    />
                                ))
                            )}
                        </Card>

                        {canEdit && (
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing ? 'Saving…' : 'Save register'}
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </Form>
        </AppLayout>
    );
}

function Count({
    label,
    value,
    hint,
    tone,
}: {
    label: string;
    value: number;
    hint: string;
    tone?: string;
}) {
    return (
        <Card className="flex flex-col gap-1 p-card sm:gap-1.5">
            <p className={smallCaps}>{label}</p>
            <p className={cn('text-stat tabular-nums', tone)}>{value}</p>
            <p className="text-xs text-muted-foreground sm:text-[0.8125rem]">
                {hint}
            </p>
        </Card>
    );
}

function CheckInBadge({
    lessonId,
    checkIn,
}: {
    lessonId: number;
    checkIn: NonNullable<RegisterProps['checkIn']>;
}) {
    if (checkIn.at !== null) {
        return (
            <span className="inline-flex items-center rounded-full bg-surface-muted px-3 py-1.5 text-sm font-semibold">
                You checked in {checkIn.at} ·{' '}
                {checkIn.minutesLate
                    ? `${checkIn.minutesLate} min late`
                    : 'on time'}
            </span>
        );
    }

    return (
        <>
            <span className="inline-flex items-center rounded-full bg-surface-muted px-3 py-1.5 text-sm font-semibold">
                You haven't checked in
            </span>
            {checkIn.canCheckIn && (
                <Form {...checkInRoute.form(lessonId)}>
                    {({ processing }) => (
                        <Button type="submit" disabled={processing}>
                            Check in
                        </Button>
                    )}
                </Form>
            )}
        </>
    );
}
