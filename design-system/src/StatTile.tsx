import type { ReactNode } from 'react';
import { cx } from './cx';

export interface StatTileProps {
    /** Uppercase caption above the number, e.g. "Sent". */
    label: string;
    /** The big number, already formatted. */
    value: ReactNode;
    /** One line of muted context under it, e.g. "12.5% of sent". */
    sub?: ReactNode;
    className?: string;
}

/** A dashboard stat: caps label, 3xl number, muted footnote, on a Card. */
export function StatTile({ label, value, sub, className }: StatTileProps) {
    return (
        <div className={cx('bg-surface border border-rule rounded-card p-5', className)}>
            <p className="text-xs font-semibold uppercase tracking-label text-ink-dim">{label}</p>
            <p className="mt-1 text-3xl font-semibold text-ink">{value}</p>
            {sub && <p className="mt-1 text-xs text-ink-dim">{sub}</p>}
        </div>
    );
}
