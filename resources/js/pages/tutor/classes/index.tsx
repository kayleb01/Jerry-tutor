import { Link } from '@inertiajs/react';
import PersonAvatar from '@/components/person-avatar';
import { Badge } from '@/components/ui/badge';
import { buttonClasses } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { create as addStudent } from '@/routes/tutor/classes/students';

type TutorClass = {
    id: number;
    name: string;
    yearGroup: string | null;
    isActive: boolean;
    canAddStudent: boolean;
    slots: { id: number; label: string }[];
    students: { id: number; name: string }[];
};

type MyClassesProps = {
    classes: TutorClass[];
};

export default function MyClasses({ classes }: MyClassesProps) {
    return (
        <AppLayout
            title="My classes"
            subtitle="The classes you teach, and who is in them."
        >
            {classes.length === 0 ? (
                <Card className="p-card">
                    <p className="text-body text-muted-foreground">
                        You don&rsquo;t teach any classes yet.
                    </p>
                </Card>
            ) : (
                <div className="grid items-start gap-3 sm:gap-4 lg:grid-cols-2">
                    {classes.map((group) => (
                        <Card key={group.id}>
                            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <CardTitle>
                                        {group.name}
                                        {group.yearGroup && (
                                            <Badge
                                                tone="neutral"
                                                className="ml-2 align-middle"
                                            >
                                                {group.yearGroup}
                                            </Badge>
                                        )}
                                        {!group.isActive && (
                                            <Badge
                                                tone="excused"
                                                className="ml-2 align-middle"
                                            >
                                                Inactive
                                            </Badge>
                                        )}
                                    </CardTitle>
                                    <CardDescription>
                                        <ul>
                                            {group.slots.map((slot) => (
                                                <li key={slot.id}>
                                                    {slot.label}
                                                </li>
                                            ))}
                                        </ul>
                                    </CardDescription>
                                </div>
                                {group.canAddStudent && (
                                    <Link
                                        href={addStudent(group.id)}
                                        className={buttonClasses(
                                            'outline',
                                            'sm',
                                        )}
                                    >
                                        Add student
                                        <span className="sr-only">
                                            {' '}
                                            to {group.name}
                                        </span>
                                    </Link>
                                )}
                            </CardHeader>
                            <CardContent className="pt-3">
                                {group.students.length === 0 ? (
                                    <p className="py-3 text-sm text-muted-foreground">
                                        No students yet.
                                    </p>
                                ) : (
                                    <ul className="divide-y">
                                        {group.students.map((student) => (
                                            <li
                                                key={student.id}
                                                className="flex items-center gap-3 py-3"
                                            >
                                                <PersonAvatar
                                                    name={student.name}
                                                    role="student"
                                                />
                                                <span className="text-sm font-semibold">
                                                    {student.name}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
