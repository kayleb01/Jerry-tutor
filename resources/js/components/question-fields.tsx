import { useState } from 'react';
import FieldError from '@/components/field-error';
import MathText from '@/components/math-text';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { Option } from '@/types';

export type QuestionFormOptions = {
    keyStages: Option[];
    strands: Option[];
    topics: {
        value: number;
        label: string;
        keyStage: string;
        strand: string;
    }[];
    difficulties: Option[];
    types: Option[];
    statuses: Option[];
    examTracks: Option[];
    yearGroups: { value: number; label: string; keyStage: string }[];
};

export type QuestionFormValues = {
    topicId: number | null;
    difficulty: string;
    yearGroup: number | null;
    type: string;
    stem: string;
    options: string[];
    correct: number[];
    numericAnswer: string | null;
    numericTolerance: string;
    acceptedAnswers: string[];
    explanation: string | null;
    marks: number;
    examTracks: string[];
    imageUrl: string | null;
};

export const emptyQuestion: QuestionFormValues = {
    topicId: null,
    difficulty: 'easy',
    yearGroup: null,
    type: 'multiple_choice',
    stem: '',
    options: ['', '', '', ''],
    correct: [],
    numericAnswer: null,
    numericTolerance: '0',
    acceptedAnswers: [],
    explanation: null,
    marks: 1,
    examTracks: [],
    imageUrl: null,
};

const letters = 'ABCDEF';

const hintClass = 'text-[13px] text-muted-foreground';

type QuestionFieldsProps = {
    initial: QuestionFormValues;
    options: QuestionFormOptions;
    errors: Partial<Record<string, string>>;
};

/** The question form body shared by create and edit, with a live student preview. */
export default function QuestionFields({
    initial,
    options,
    errors,
}: QuestionFieldsProps) {
    const initialTopic = options.topics.find(
        (topic) => topic.value === initial.topicId,
    );
    const [keyStage, setKeyStage] = useState(
        initialTopic?.keyStage ?? String(options.keyStages[0]?.value ?? ''),
    );
    const [strand, setStrand] = useState(
        initialTopic?.strand ?? String(options.strands[0]?.value ?? ''),
    );
    const [topicId, setTopicId] = useState(
        initial.topicId ? String(initial.topicId) : '',
    );
    const [yearGroup, setYearGroup] = useState(
        initial.yearGroup ? String(initial.yearGroup) : '',
    );
    const [type, setType] = useState(initial.type);
    const [stem, setStem] = useState(initial.stem);
    const [choices, setChoices] = useState(
        initial.options.length > 0 ? initial.options : ['', '', '', ''],
    );
    const [correct, setCorrect] = useState<number[]>(initial.correct);
    const [explanation, setExplanation] = useState(initial.explanation ?? '');
    const [removeImage, setRemoveImage] = useState(false);

    const hasOptions = type === 'multiple_choice' || type === 'multi_select';
    const topics = options.topics.filter(
        (topic) => topic.keyStage === keyStage && topic.strand === strand,
    );
    const yearGroups = options.yearGroups.filter(
        (year) => year.keyStage === keyStage,
    );

    const chooseKeyStage = (value: string) => {
        setKeyStage(value);
        setTopicId('');
        setYearGroup('');
    };

    const chooseStrand = (value: string) => {
        setStrand(value);
        setTopicId('');
    };

    const chooseType = (value: string) => {
        setType(value);

        if (value === 'multiple_choice') {
            setCorrect((current) => current.slice(0, 1));
        }
    };

    const toggleCorrect = (index: number) =>
        setCorrect((current) => {
            if (type === 'multiple_choice') {
                return [index];
            }

            return current.includes(index)
                ? current.filter((value) => value !== index)
                : [...current, index];
        });

    const updateChoice = (index: number, value: string) =>
        setChoices((current) =>
            current.map((choice, i) => (i === index ? value : choice)),
        );

    const removeChoice = (index: number) => {
        setChoices((current) => current.filter((_, i) => i !== index));
        setCorrect((current) =>
            current
                .filter((value) => value !== index)
                .map((value) => (value > index ? value - 1 : value)),
        );
    };

    const correctError =
        errors.correct ??
        Object.entries(errors).find(([key]) => key.startsWith('correct.'))?.[1];

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:items-start">
            <div className="flex min-w-0 flex-col gap-4">
                <Card className="grid gap-4 p-card sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="key_stage">Key stage</Label>
                        <Select
                            id="key_stage"
                            value={keyStage}
                            onChange={(event) =>
                                chooseKeyStage(event.target.value)
                            }
                        >
                            {options.keyStages.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="strand">Strand</Label>
                        <Select
                            id="strand"
                            value={strand}
                            onChange={(event) =>
                                chooseStrand(event.target.value)
                            }
                        >
                            {options.strands.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="topic_id">Topic</Label>
                        <Select
                            id="topic_id"
                            name="topic_id"
                            value={topicId}
                            onChange={(event) => setTopicId(event.target.value)}
                            required
                            aria-invalid={!!errors.topic_id}
                            aria-describedby="topic_id-error"
                        >
                            <option value="">Choose a topic</option>
                            {topics.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <FieldError
                            id="topic_id-error"
                            message={errors.topic_id}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="year_group">
                            Year group (optional)
                        </Label>
                        <Select
                            id="year_group"
                            name="year_group"
                            value={yearGroup}
                            onChange={(event) =>
                                setYearGroup(event.target.value)
                            }
                            aria-invalid={!!errors.year_group}
                            aria-describedby="year_group-error"
                        >
                            <option value="">Whole key stage</option>
                            {yearGroups.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <FieldError
                            id="year_group-error"
                            message={errors.year_group}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="type">Answer type</Label>
                        <Select
                            id="type"
                            name="type"
                            value={type}
                            onChange={(event) => chooseType(event.target.value)}
                            aria-invalid={!!errors.type}
                            aria-describedby="type-error"
                        >
                            {options.types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <FieldError id="type-error" message={errors.type} />
                    </div>
                    <div className="grid grid-cols-[minmax(0,1fr)_5.5rem] gap-3">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="difficulty">Difficulty</Label>
                            <Select
                                id="difficulty"
                                name="difficulty"
                                defaultValue={initial.difficulty}
                                aria-invalid={!!errors.difficulty}
                                aria-describedby="difficulty-error"
                            >
                                {options.difficulties.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <FieldError
                                id="difficulty-error"
                                message={errors.difficulty}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="marks">Marks</Label>
                            <Input
                                id="marks"
                                name="marks"
                                type="number"
                                min={1}
                                max={5}
                                defaultValue={initial.marks}
                                aria-invalid={!!errors.marks}
                                aria-describedby="marks-error"
                            />
                            <FieldError
                                id="marks-error"
                                message={errors.marks}
                            />
                        </div>
                    </div>
                </Card>

                <Card className="flex flex-col gap-4 p-card">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="stem">Question</Label>
                        <Textarea
                            id="stem"
                            name="stem"
                            rows={4}
                            value={stem}
                            onChange={(event) => setStem(event.target.value)}
                            maxLength={5000}
                            required
                            aria-invalid={!!errors.stem}
                            aria-describedby="stem-hint stem-error"
                        />
                        <p id="stem-hint" className={hintClass}>
                            Put maths between dollar signs, e.g.{' '}
                            <code>$\frac{'{3}{4}'}$</code> or <code>$2^3$</code>
                            . Type <code>\$</code> for a dollar sign.
                        </p>
                        <FieldError id="stem-error" message={errors.stem} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="image">Diagram (optional)</Label>
                        {initial.imageUrl && !removeImage && (
                            <img
                                src={initial.imageUrl}
                                alt="Current diagram"
                                className="max-h-48 w-fit rounded-md border object-contain"
                            />
                        )}
                        <input
                            id="image"
                            name="image"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            className="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-surface file:px-3 file:py-1.5 file:text-sm file:font-semibold"
                            aria-invalid={!!errors.image}
                            aria-describedby="image-hint image-error"
                        />
                        <p id="image-hint" className={hintClass}>
                            PNG, JPG or WebP, up to 2 MB.
                        </p>
                        {initial.imageUrl && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="remove_image"
                                    value="1"
                                    checked={removeImage}
                                    onChange={(event) =>
                                        setRemoveImage(event.target.checked)
                                    }
                                    className="size-4 accent-primary"
                                />
                                Remove the current diagram
                            </label>
                        )}
                        <FieldError id="image-error" message={errors.image} />
                    </div>
                </Card>

                {hasOptions && (
                    <Card className="flex flex-col gap-3 p-card">
                        <div>
                            <h2 className="text-base font-semibold sm:text-lg">
                                Answer options
                            </h2>
                            <p className={hintClass}>
                                {type === 'multiple_choice'
                                    ? 'Tick the correct answer.'
                                    : 'Tick every correct answer.'}
                            </p>
                        </div>
                        <ol className="flex flex-col gap-3">
                            {choices.map((choice, index) => (
                                <li
                                    key={index}
                                    className="flex flex-col gap-1.5"
                                >
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5 sm:flex-nowrap sm:gap-3">
                                        <span
                                            className="w-4 shrink-0 text-sm font-semibold"
                                            aria-hidden="true"
                                        >
                                            {letters[index]}
                                        </span>
                                        <Input
                                            name="options[]"
                                            className="w-auto min-w-0 flex-1"
                                            value={choice}
                                            onChange={(event) =>
                                                updateChoice(
                                                    index,
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={500}
                                            aria-label={`Option ${letters[index]}`}
                                            aria-invalid={
                                                !!errors[`options.${index}`]
                                            }
                                            aria-describedby={`options-${index}-error`}
                                        />
                                        <div className="flex basis-full items-center gap-3 pl-6 sm:basis-auto sm:pl-0">
                                            <label className="flex shrink-0 items-center gap-1.5 text-sm">
                                                <input
                                                    type={
                                                        type ===
                                                        'multiple_choice'
                                                            ? 'radio'
                                                            : 'checkbox'
                                                    }
                                                    name="correct[]"
                                                    value={index}
                                                    checked={correct.includes(
                                                        index,
                                                    )}
                                                    onChange={() =>
                                                        toggleCorrect(index)
                                                    }
                                                    className="size-4 accent-primary"
                                                />
                                                Correct
                                            </label>
                                            {choices.length > 2 && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeChoice(index)
                                                    }
                                                    aria-label={`Remove option ${letters[index]}`}
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    <FieldError
                                        id={`options-${index}-error`}
                                        message={errors[`options.${index}`]}
                                    />
                                </li>
                            ))}
                        </ol>
                        {choices.length < 6 && (
                            <div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setChoices((current) => [
                                            ...current,
                                            '',
                                        ])
                                    }
                                >
                                    Add option
                                </Button>
                            </div>
                        )}
                        <FieldError
                            id="options-error"
                            message={errors.options}
                        />
                        <FieldError id="correct-error" message={correctError} />
                    </Card>
                )}

                {type === 'numeric' && (
                    <Card className="grid gap-4 p-card sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="numeric_answer">
                                Correct number
                            </Label>
                            <Input
                                id="numeric_answer"
                                name="numeric_answer"
                                inputMode="decimal"
                                defaultValue={initial.numericAnswer ?? ''}
                                required
                                aria-invalid={!!errors.numeric_answer}
                                aria-describedby="numeric_answer-error"
                            />
                            <FieldError
                                id="numeric_answer-error"
                                message={errors.numeric_answer}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="numeric_tolerance">Tolerance</Label>
                            <Input
                                id="numeric_tolerance"
                                name="numeric_tolerance"
                                inputMode="decimal"
                                defaultValue={initial.numericTolerance}
                                aria-invalid={!!errors.numeric_tolerance}
                                aria-describedby="numeric_tolerance-hint numeric_tolerance-error"
                            />
                            <p
                                id="numeric_tolerance-hint"
                                className={hintClass}
                            >
                                Answers within this much of the correct number
                                are marked right.
                            </p>
                            <FieldError
                                id="numeric_tolerance-error"
                                message={errors.numeric_tolerance}
                            />
                        </div>
                    </Card>
                )}

                {type === 'short_text' && (
                    <Card className="flex flex-col gap-1.5 p-card">
                        <Label htmlFor="accepted_answers">
                            Accepted answers
                        </Label>
                        <Textarea
                            id="accepted_answers"
                            name="accepted_answers"
                            rows={3}
                            defaultValue={initial.acceptedAnswers.join('\n')}
                            aria-invalid={!!errors.accepted_answers}
                            aria-describedby="accepted_answers-hint accepted_answers-error"
                        />
                        <p id="accepted_answers-hint" className={hintClass}>
                            One per line. Capital letters don’t matter.
                        </p>
                        <FieldError
                            id="accepted_answers-error"
                            message={
                                errors.accepted_answers ??
                                Object.entries(errors).find(([key]) =>
                                    key.startsWith('accepted_answers.'),
                                )?.[1]
                            }
                        />
                    </Card>
                )}

                <Card className="flex flex-col gap-4 p-card">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="explanation">
                            Worked explanation (optional)
                        </Label>
                        <Textarea
                            id="explanation"
                            name="explanation"
                            rows={3}
                            value={explanation}
                            onChange={(event) =>
                                setExplanation(event.target.value)
                            }
                            maxLength={5000}
                            aria-invalid={!!errors.explanation}
                            aria-describedby="explanation-hint explanation-error"
                        />
                        <p id="explanation-hint" className={hintClass}>
                            Shown to students after they answer.
                        </p>
                        <FieldError
                            id="explanation-error"
                            message={errors.explanation}
                        />
                    </div>
                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-1.5 text-sm font-semibold">
                            Exam tracks (optional)
                        </legend>
                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                            {options.examTracks.map((track) => (
                                <label
                                    key={track.value}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        name="exam_tracks[]"
                                        value={track.value}
                                        defaultChecked={initial.examTracks.includes(
                                            String(track.value),
                                        )}
                                        className="size-4 accent-primary"
                                    />
                                    {track.label}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                </Card>
            </div>

            <Card
                className="flex flex-col gap-3 p-card lg:sticky lg:top-6"
                aria-label="Student preview"
            >
                <p className="text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:text-xs">
                    Student preview
                </p>
                <p className="text-base font-semibold break-words sm:text-lg">
                    {stem.trim() ? (
                        <MathText text={stem} />
                    ) : (
                        <span className="font-normal text-muted-foreground">
                            Your question appears here.
                        </span>
                    )}
                </p>
                {hasOptions && (
                    <ul className="flex flex-col gap-2">
                        {choices.map((choice, index) => (
                            <li
                                key={index}
                                className="flex items-start gap-2 rounded-md border px-3 py-2 text-sm"
                            >
                                <span className="font-semibold">
                                    {letters[index]}
                                </span>
                                <MathText text={choice || '…'} />
                            </li>
                        ))}
                    </ul>
                )}
                {type === 'numeric' && (
                    <p className="rounded-md border px-3 py-2 text-sm text-muted-foreground">
                        Students type a number.
                    </p>
                )}
                {type === 'short_text' && (
                    <p className="rounded-md border px-3 py-2 text-sm text-muted-foreground">
                        Students type a short answer.
                    </p>
                )}
                {explanation.trim() && (
                    <div className="rounded-md bg-surface-muted p-3 text-sm">
                        <p className="mb-1 font-semibold">Explanation</p>
                        <MathText text={explanation} />
                    </div>
                )}
            </Card>
        </div>
    );
}
