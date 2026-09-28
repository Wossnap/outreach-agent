import type { HTMLAttributes } from 'react';
import { cx } from './cx';

export interface PillProps extends HTMLAttributes<HTMLSpanElement> {
    /**
     * The meaning, not the colour.
     * good = solid navy (ok, valid, healthy, sent, active);
     * warn = gold outline (risky, pending, paused);
     * danger = brick outline (invalid, bounced, failed, critical);
     * neutral = sand fill (unknown, not checked, plain counts, tags).
     */
    tone?: 'good' | 'warn' | 'danger' | 'neutral';
}

const tones = {
    neutral: 'bg-band text-ink border-rule',
    good: 'bg-brand text-brand-ink border-brand',
    warn: 'text-warn border-warn',
    danger: 'text-danger border-danger',
};

/** A status pill: uppercase 11px label with +8% tracking, full radius. Also used for tags and count badges. */
export function Pill({ tone = 'neutral', className, ...rest }: PillProps) {
    return (
        <span
            data-tone={tone}
            className={cx(
                'inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold uppercase tracking-label whitespace-nowrap',
                tones[tone],
                className,
            )}
            {...rest}
        />
    );
}
