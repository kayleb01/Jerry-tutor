import { Form } from '@inertiajs/react';
import Icon from '@/components/icon';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { acknowledge } from '@/routes/raised-issues';

export type AcademyNote = {
    id: number;
    className: string;
    when: string;
    note: string;
    raisedBy: string | null;
};

/** Notes the academy raised about the tutor's classes, until the tutor marks them read. */
export default function FromAcademy({ notes }: { notes: AcademyNote[] }) {
    if (notes.length === 0) {
        return null;
    }

    return (
        <Card className="border-primary-90 bg-primary-95 dark:border-primary-20 dark:bg-primary-20/40">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon
                        name="flag"
                        className="text-primary-30 dark:text-primary-80"
                    />
                    From the academy
                </CardTitle>
                <CardDescription>
                    Notes about your classes from the academy office.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y divide-primary-90 dark:divide-primary-20">
                    {notes.map((note) => (
                        <li
                            key={note.id}
                            className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-semibold">
                                    {note.className} · {note.when}
                                </p>
                                <p className="mt-1 break-words">{note.note}</p>
                                {note.raisedBy && (
                                    <p className="mt-1 text-[13px] text-muted-foreground">
                                        From {note.raisedBy}
                                    </p>
                                )}
                            </div>
                            <Form
                                {...acknowledge.form(note.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Got it
                                        <span className="sr-only">
                                            {' '}
                                            (note about {note.className})
                                        </span>
                                    </Button>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
