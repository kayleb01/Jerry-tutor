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
import type { RouteFormDefinition } from '@/wayfinder';

export type AcademyNote = {
    id: number;
    /** e.g. "Year 4 Number · Tue 13 Oct" or "Tolu Ade · Wed 14 Oct". */
    heading: string;
    note: string;
    from: string | null;
};

type FromAcademyProps = {
    notes: AcademyNote[];
    description: string;
    acknowledge: (id: number) => RouteFormDefinition<'post'>;
};

/** Notes from the academy office, shown until the reader marks them as read. */
export default function FromAcademy({
    notes,
    description,
    acknowledge,
}: FromAcademyProps) {
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
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="divide-y divide-primary-90 dark:divide-primary-20">
                    {notes.map((note) => (
                        <li
                            key={note.id}
                            className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-semibold">{note.heading}</p>
                                <p className="mt-1 break-words">{note.note}</p>
                                {note.from && (
                                    <p className="mt-1 text-[0.8125rem] text-muted-foreground">
                                        From {note.from}
                                    </p>
                                )}
                            </div>
                            <Form
                                {...acknowledge(note.id)}
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
                                            ({note.heading})
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
