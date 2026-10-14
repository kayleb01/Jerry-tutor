import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import DifficultyBadge from '@/components/difficulty-badge';
import type { Difficulty } from '@/components/difficulty-badge';
import MathText from '@/components/math-text';
import type { QuestionFormOptions } from '@/components/question-fields';
import QuestionStatusBadge from '@/components/question-status-badge';
import type { QuestionStatus } from '@/components/question-status-badge';
import { buttonClasses } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { bulkStatus, create, edit, index } from '@/routes/admin/questions';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    stem: string;
    topic: string;
    strand: string;
    keyStage: string;
    yearGroup: string | null;
    difficulty: Difficulty;
    type: string;
    status: QuestionStatus;
    statusLabel: string;
    examTracks: string[];
    updatedAt: string;
    canDelete: boolean;
    nextStatuses: Option[];
};

type Filters = {
    search: string;
    key_stage: string | null;
    strand: string | null;
    topic: number | null;
    difficulty: string | null;
    type: string | null;
    status: string | null;
    exam_track: string | null;
};

type QuestionsIndexProps = {
    questions: Paginated<Row>;
    filters: Filters;
    counts: Record<QuestionStatus, number>;
    options: QuestionFormOptions;
};

const bulkActions = [
    { status: 'in_review', label: 'Send for review', variant: 'outline' },
    { status: 'published', label: 'Publish', variant: 'primary' },
    { status: 'retired', label: 'Retire', variant: 'outline' },
] as const;

/** Drops empty filters so links stay short. */
function query(filters: Filters, overrides: Partial<Filters> = {}) {
    return Object.fromEntries(
        Object.entries({ ...filters, ...overrides }).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );
}

export default function QuestionsIndex({
    questions,
    filters,
    counts,
    options,
}: QuestionsIndexProps) {
    const [keyStage, setKeyStage] = useState(filters.key_stage ?? '');
    const [strand, setStrand] = useState(filters.strand ?? '');
    const [selected, setSelected] = useState<number[]>([]);
    const pageIds = questions.data.map((question) => question.id);
    const allSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    const topics = options.topics.filter(
        (topic) =>
            (keyStage === '' || topic.keyStage === keyStage) &&
            (strand === '' || topic.strand === strand),
    );
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const isFiltered = Object.entries(filters).some(
        ([key, value]) => key !== 'status' && value !== null && value !== '',
    );
    const tabs = [
        { value: null, label: 'All', count: total },
        ...options.statuses.map((status) => ({
            value: String(status.value),
            label: status.label,
            count: counts[status.value as QuestionStatus] ?? 0,
        })),
    ];

    return (
        <AppLayout title="Question bank" subtitle="Quizzes">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <nav
                    aria-label="Question status"
                    className="-mx-4 flex gap-1 overflow-x-auto px-4 sm:mx-0 sm:px-0"
                >
                    {tabs.map((tab) => {
                        const active = (filters.status ?? null) === tab.value;

                        return (
                            <Link
                                key={tab.label}
                                href={index({
                                    query: query(filters, {
                                        status: tab.value,
                                    }),
                                })}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-semibold whitespace-nowrap',
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-surface-muted hover:text-foreground',
                                )}
                            >
                                {tab.label}
                                <span className="tabular-nums opacity-75">
                                    {tab.count}
                                </span>
                            </Link>
                        );
                    })}
                </nav>
                <Link href={create()} className={buttonClasses('primary')}>
                    Add question
                </Link>
            </div>

            <Card className="p-card">
                <Form
                    {...index.form()}
                    options={{ preserveState: true }}
                    className="grid grid-cols-2 items-end gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:flex xl:flex-wrap"
                    aria-label="Filter questions"
                >
                    {filters.status && (
                        <input
                            type="hidden"
                            name="status"
                            value={filters.status}
                        />
                    )}
                    <div className="col-span-2 flex flex-col gap-1.5 sm:col-span-3 lg:col-span-4 xl:w-64">
                        <Label htmlFor="search">Search</Label>
                        <Input
                            id="search"
                            name="search"
                            type="search"
                            defaultValue={filters.search}
                            placeholder="Words in the question"
                        />
                    </div>
                    <div className="flex flex-col gap-1.5 xl:w-32">
                        <Label htmlFor="key_stage">Key stage</Label>
                        <Select
                            id="key_stage"
                            name="key_stage"
                            value={keyStage}
                            onChange={(event) =>
                                setKeyStage(event.target.value)
                            }
                        >
                            <option value="">All</option>
                            {options.keyStages.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 xl:w-36">
                        <Label htmlFor="strand">Strand</Label>
                        <Select
                            id="strand"
                            name="strand"
                            value={strand}
                            onChange={(event) => setStrand(event.target.value)}
                        >
                            <option value="">All</option>
                            {options.strands.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 xl:w-52">
                        <Label htmlFor="topic">Topic</Label>
                        <Select
                            id="topic"
                            name="topic"
                            defaultValue={filters.topic ?? ''}
                        >
                            <option value="">All topics</option>
                            {topics.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 xl:w-40">
                        <Label htmlFor="difficulty">Difficulty</Label>
                        <Select
                            id="difficulty"
                            name="difficulty"
                            defaultValue={filters.difficulty ?? ''}
                        >
                            <option value="">All</option>
                            {options.difficulties.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 xl:w-48">
                        <Label htmlFor="type">Answer type</Label>
                        <Select
                            id="type"
                            name="type"
                            defaultValue={filters.type ?? ''}
                        >
                            <option value="">All</option>
                            {options.types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5 xl:w-40">
                        <Label htmlFor="exam_track">Exam track</Label>
                        <Select
                            id="exam_track"
                            name="exam_track"
                            defaultValue={filters.exam_track ?? ''}
                        >
                            <option value="">All</option>
                            {options.examTracks.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="col-span-2 flex items-center gap-3 sm:col-span-1">
                        <Button type="submit" variant="outline">
                            Filter
                        </Button>
                        {isFiltered && (
                            <Link
                                href={index({
                                    query: query({
                                        search: '',
                                        key_stage: null,
                                        strand: null,
                                        topic: null,
                                        difficulty: null,
                                        type: null,
                                        exam_track: null,
                                        status: filters.status,
                                    }),
                                })}
                                className="text-sm font-semibold text-link hover:underline"
                            >
                                Reset
                            </Link>
                        )}
                    </div>
                </Form>
            </Card>

            {selected.length > 0 && (
                <div
                    role="region"
                    aria-label="Bulk actions"
                    className="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-lg border bg-surface p-3 shadow-lg"
                >
                    <span className="text-sm font-semibold">
                        {selected.length} selected
                    </span>
                    {bulkActions.map((action) => (
                        <Form
                            key={action.status}
                            {...bulkStatus.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setSelected([])}
                        >
                            {({ processing }) => (
                                <>
                                    {selected.map((id) => (
                                        <input
                                            key={id}
                                            type="hidden"
                                            name="ids[]"
                                            value={id}
                                        />
                                    ))}
                                    <input
                                        type="hidden"
                                        name="status"
                                        value={action.status}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant={action.variant}
                                        disabled={processing}
                                    >
                                        {action.label}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ))}
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setSelected([])}
                    >
                        Clear
                    </Button>
                </div>
            )}

            <Card>
                <CardContent className="overflow-x-auto p-0">
                    <table className="w-full min-w-[720px] text-left text-sm">
                        <thead className="border-b text-xs tracking-[0.04em] text-muted-foreground uppercase">
                            <tr>
                                <th scope="col" className="w-10 py-3 pl-4">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        onChange={() =>
                                            setSelected(
                                                allSelected ? [] : pageIds,
                                            )
                                        }
                                        aria-label="Select every question on this page"
                                        className="size-4 accent-primary"
                                    />
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Question
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Topic
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Difficulty
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-semibold"
                                >
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {questions.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-6 text-center text-muted-foreground sm:py-8"
                                    >
                                        {total === 0
                                            ? 'The bank is empty. Add a question to get started.'
                                            : 'No questions match these filters.'}
                                    </td>
                                </tr>
                            )}
                            {questions.data.map((question) => (
                                <tr
                                    key={question.id}
                                    className="border-b align-top last:border-0"
                                >
                                    <td className="py-3 pl-4">
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(
                                                question.id,
                                            )}
                                            onChange={() => toggle(question.id)}
                                            aria-label={`Select question ${question.id}`}
                                            className="size-4 accent-primary"
                                        />
                                    </td>
                                    <td className="max-w-md px-4 py-3">
                                        <MathText
                                            text={question.stem}
                                            className="line-clamp-2 font-medium"
                                        />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {question.type}
                                            {question.examTracks.length > 0 &&
                                                ` · ${question.examTracks.join(', ')}`}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p>{question.topic}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {question.keyStage}
                                            {question.yearGroup &&
                                                ` · ${question.yearGroup}`}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <DifficultyBadge
                                            difficulty={question.difficulty}
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <QuestionStatusBadge
                                            status={question.status}
                                            label={question.statusLabel}
                                        />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {question.updatedAt}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={edit(question.id)}
                                            className="font-semibold text-link hover:underline"
                                        >
                                            Edit
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <nav
                className="flex items-center justify-between text-sm"
                aria-label="Pagination"
            >
                <span className="text-muted-foreground">
                    {questions.total}{' '}
                    {questions.total === 1 ? 'question' : 'questions'} · page{' '}
                    {questions.current_page} of {questions.last_page}
                </span>
                <div className="flex gap-3">
                    {questions.prev_page_url && (
                        <Link
                            href={questions.prev_page_url}
                            preserveState
                            className="font-medium text-link"
                        >
                            Previous
                        </Link>
                    )}
                    {questions.next_page_url && (
                        <Link
                            href={questions.next_page_url}
                            preserveState
                            className="font-medium text-link"
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </AppLayout>
    );
}
