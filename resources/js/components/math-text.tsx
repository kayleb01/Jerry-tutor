import katex from 'katex';
import 'katex/dist/katex.min.css';
import { Fragment, useMemo } from 'react';
import { cn } from '@/lib/utils';

type Segment =
    | { kind: 'text'; value: string }
    | { kind: 'math'; value: string; display: boolean };

/**
 * Splits text on $…$ (inline) and $$…$$ (display) maths. "\$" is a literal dollar sign,
 * and an unclosed "$" is kept as text.
 */
export function splitMath(text: string): Segment[] {
    const segments: Segment[] = [];
    let buffer = '';
    let index = 0;

    while (index < text.length) {
        if (text.startsWith('\\$', index)) {
            buffer += '$';
            index += 2;
            continue;
        }

        if (text[index] === '$') {
            const display = text.startsWith('$$', index);
            const delimiter = display ? '$$' : '$';
            const close = text.indexOf(delimiter, index + delimiter.length);

            if (close > index + delimiter.length) {
                if (buffer) {
                    segments.push({ kind: 'text', value: buffer });
                    buffer = '';
                }

                segments.push({
                    kind: 'math',
                    value: text.slice(index + delimiter.length, close),
                    display,
                });
                index = close + delimiter.length;
                continue;
            }
        }

        buffer += text[index];
        index += 1;
    }

    if (buffer) {
        segments.push({ kind: 'text', value: buffer });
    }

    return segments;
}

type MathTextProps = {
    text: string;
    className?: string;
};

/** Plain text with KaTeX maths. Line breaks in the text are kept. */
export default function MathText({ text, className }: MathTextProps) {
    const segments = useMemo(() => splitMath(text), [text]);

    return (
        <span className={cn('whitespace-pre-line', className)}>
            {segments.map((segment, index) =>
                segment.kind === 'text' ? (
                    <Fragment key={index}>{segment.value}</Fragment>
                ) : (
                    <span
                        key={index}
                        className={cn(segment.display && 'my-2 block')}
                        // KaTeX escapes its input; trust is off, so \href and \url are not rendered.
                        dangerouslySetInnerHTML={{
                            __html: katex.renderToString(segment.value, {
                                displayMode: segment.display,
                                throwOnError: false,
                                trust: false,
                                output: 'htmlAndMathml',
                            }),
                        }}
                    />
                ),
            )}
        </span>
    );
}
