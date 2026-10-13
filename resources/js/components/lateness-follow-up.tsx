import { Form } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '@/components/field-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { update } from '@/routes/admin/lateness';

type LatenessFollowUpProps = {
    reportId: number;
    name: string;
    notes: string | null;
    reviewedBy: string | null;
    reviewedAt: string | null;
};

export default function LatenessFollowUp({
    reportId,
    name,
    notes,
    reviewedBy,
    reviewedAt,
}: LatenessFollowUpProps) {
    const [open, setOpen] = useState(false);
    const notesId = `follow-up-${reportId}`;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                {reviewedBy ? (
                    <Badge tone="present">
                        Reviewed by {reviewedBy}
                        {reviewedAt ? ` · ${reviewedAt}` : ''}
                    </Badge>
                ) : (
                    <Badge tone="neutral">Not reviewed</Badge>
                )}
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    aria-controls={`${notesId}-form`}
                    className="text-sm font-medium text-link underline-offset-4 hover:underline"
                >
                    {open ? 'Close' : 'Follow up'}
                    <span className="sr-only"> on {name}'s lateness</span>
                </button>
            </div>
            {notes && !open && (
                <p className="text-xs whitespace-pre-line text-muted-foreground">
                    {notes}
                </p>
            )}
            {open && (
                <Form
                    {...update.form(reportId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    id={`${notesId}-form`}
                    className="flex flex-col gap-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <label
                                htmlFor={notesId}
                                className="text-xs font-medium"
                            >
                                Follow-up notes
                            </label>
                            <textarea
                                id={notesId}
                                name="follow_up_notes"
                                defaultValue={notes ?? ''}
                                maxLength={2000}
                                className="min-h-20 w-full rounded-sm border border-input bg-surface px-3.5 py-2.5 text-sm"
                                aria-invalid={!!errors.follow_up_notes}
                                aria-describedby={`${notesId}-error`}
                            />
                            <FieldError
                                id={`${notesId}-error`}
                                message={errors.follow_up_notes}
                            />
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="reviewed"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="reviewed"
                                    value="1"
                                    defaultChecked={reviewedBy !== null}
                                    className="size-4 accent-primary"
                                />
                                Mark as reviewed
                            </label>
                            <div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Save follow-up
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
