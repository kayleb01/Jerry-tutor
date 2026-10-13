import { Form, Link } from '@inertiajs/react';
import AttendanceStatusBadge from '@/components/attendance-status-badge';
import type { AttendanceStatus } from '@/components/attendance-status-badge';
import FieldError from '@/components/field-error';
import RegisterRow from '@/components/register-row';
import type { RegisterPerson } from '@/components/register-row';
import { Button, buttonClasses } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/lessons/register';
import { edit as editReport } from '@/routes/lessons/report';
import type { Option } from '@/types';

type RegisterProps = {
    lesson: {
        id: number;
        className: string;
        date: string;
        time: string;
        location: string | null;
        status: string;
    };
    tutor: RegisterPerson;
    students: RegisterPerson[];
    canEditTutor: boolean;
    canReport: boolean;
    statuses: Option[];
};

export default function Register({
    lesson,
    tutor,
    students,
    canEditTutor,
    canReport,
    statuses,
}: RegisterProps) {
    const startTime = lesson.time.split('–')[0];

    return (
        <AppLayout
            title={lesson.className}
            subtitle={`Register · ${lesson.date}, ${lesson.time}${lesson.location ? ` · ${lesson.location}` : ''}`}
            actions={
                canReport && (
                    <Link
                        href={editReport(lesson.id)}
                        className={buttonClasses('outline')}
                    >
                        Write end-of-class report
                    </Link>
                )
            }
        >
            <Form {...update.form(lesson.id)} className="flex flex-col gap-6">
                {({ errors, processing }) => (
                    <>
                        <FieldError
                            id="attendance-error"
                            message={errors.attendance}
                        />
                        <Card>
                            <CardHeader>
                                <CardTitle>Tutor</CardTitle>
                                <CardDescription>
                                    Tutors check in from their Today screen.
                                    Admins can correct the tutor's record here.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {canEditTutor ? (
                                    <RegisterRow
                                        person={tutor}
                                        statuses={statuses}
                                        errors={errors}
                                        defaultTime={startTime}
                                    />
                                ) : (
                                    <div className="flex items-center justify-between">
                                        <span className="font-semibold">
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
                                                Not checked in
                                            </span>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    Students ({students.length})
                                </CardTitle>
                                <CardDescription>
                                    Mark each student. For a late arrival, enter
                                    the time they arrived; lateness is worked
                                    out automatically.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {students.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No students are enrolled in this class.
                                    </p>
                                ) : (
                                    students.map((student) => (
                                        <RegisterRow
                                            key={student.id}
                                            person={student}
                                            statuses={statuses}
                                            errors={errors}
                                            defaultTime={startTime}
                                        />
                                    ))
                                )}
                            </CardContent>
                        </Card>
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing}
                            >
                                {processing ? 'Saving…' : 'Save register'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AppLayout>
    );
}
