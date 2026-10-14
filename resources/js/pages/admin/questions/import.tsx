import { Form, usePage } from '@inertiajs/react';
import FieldError from '@/components/field-error';
import Icon from '@/components/icon';
import { Button } from '@/components/ui/button';
import { buttonClasses } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { store, template } from '@/routes/admin/questions/import';

const descriptions: Record<string, string> = {
    key_stage: 'KS2, KS3 or GCSE.',
    strand: 'Number, Algebra, Statistics or Geometry.',
    topic: 'A topic name from the bank, e.g. Fractions.',
    year_group: 'Optional. 3 to 11, inside the key stage.',
    difficulty: 'Easy, Intermediate, Hard or Challenging.',
    type: 'multiple_choice, multi_select, numeric or short_text.',
    stem: 'The question. Put maths between dollar signs, e.g. $\\frac{3}{4}$.',
    option_a: 'Options A to F for choice questions. Leave unused ones blank.',
    correct: 'Option letters, like B or A;C.',
    numeric_answer: 'The correct number, for numeric questions.',
    numeric_tolerance: 'Optional. How far off still counts as right.',
    accepted_answers: 'For short answers, separated by |, e.g. x = 4|4.',
    explanation: 'Optional worked solution shown after answering.',
    marks: 'Optional. 1 to 5, default 1.',
    exam_tracks: 'Optional. 11+, SATs or GCSE, separated by |.',
};

type ImportQuestionsProps = {
    columns: string[];
    required: string[];
    maxRows: number;
};

export default function ImportQuestions({
    columns,
    required,
    maxRows,
}: ImportQuestionsProps) {
    const { flash } = usePage();
    const shown = columns.filter((column) => !/^option_[b-f]$/.test(column));

    return (
        <AppLayout title="Upload questions" subtitle="Question bank">
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] lg:items-start">
                <Card className="flex flex-col gap-4 p-card">
                    <div>
                        <h2 className="text-base font-semibold sm:text-lg">
                            How it works
                        </h2>
                        <ol className="mt-2 flex list-decimal flex-col gap-1 pl-5 text-sm">
                            <li>Download the template.</li>
                            <li>Fill in one row per question.</li>
                            <li>Upload the file here.</li>
                        </ol>
                        <p className="mt-2 text-[13px] text-muted-foreground">
                            Every question is imported as a draft for review. If
                            any row has a problem, nothing is imported and
                            you’ll see which rows to fix. Up to {maxRows}{' '}
                            questions per file.
                        </p>
                    </div>
                    <div>
                        <a
                            href={template.url()}
                            download
                            className={buttonClasses('outline', 'sm')}
                        >
                            Download the template
                        </a>
                    </div>
                    <div className="-mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
                        <table className="w-full min-w-[480px] text-left text-sm">
                            <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                                <tr>
                                    <th
                                        scope="col"
                                        className="py-2 pr-4 font-semibold"
                                    >
                                        Column
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 font-semibold"
                                    >
                                        What to put in it
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((column) => (
                                    <tr
                                        key={column}
                                        className="border-b align-top last:border-0"
                                    >
                                        <td className="py-2 pr-4 font-mono text-[13px] whitespace-nowrap">
                                            {column === 'option_a'
                                                ? 'option_a … option_f'
                                                : column}
                                            {required.includes(column) && (
                                                <span className="ml-1 font-sans text-xs text-muted-foreground">
                                                    required
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2">
                                            {descriptions[column] ?? ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card className="p-card lg:sticky lg:top-6">
                    <Form
                        {...store.form()}
                        className="flex flex-col gap-3"
                        resetOnSuccess
                    >
                        {({ errors, processing, progress }) => (
                            <>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="file">CSV file</Label>
                                    <input
                                        id="file"
                                        name="file"
                                        type="file"
                                        accept=".csv,text/csv"
                                        required
                                        className="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-surface file:px-3 file:py-1.5 file:text-sm file:font-semibold"
                                        aria-invalid={!!errors.file}
                                        aria-describedby="file-error"
                                    />
                                    <FieldError
                                        id="file-error"
                                        message={errors.file}
                                    />
                                </div>
                                {flash.importErrors &&
                                    flash.importErrors.length > 0 && (
                                        <div
                                            role="alert"
                                            className="rounded-md border border-danger/30 bg-danger/5 p-3 text-[13px]"
                                        >
                                            <ul className="flex flex-col gap-1">
                                                {flash.importErrors.map(
                                                    (error) => (
                                                        <li key={error}>
                                                            {error}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                {progress && (
                                    <progress
                                        value={progress.percentage}
                                        max={100}
                                        className="w-full"
                                    >
                                        {progress.percentage}%
                                    </progress>
                                )}
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        <Icon
                                            name="arrow-right"
                                            className="size-4"
                                        />
                                        Upload
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Card>
            </div>
        </AppLayout>
    );
}
