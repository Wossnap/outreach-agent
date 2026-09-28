import type { HTMLAttributes } from 'react';
import { cx } from './cx';

export interface BannerProps extends HTMLAttributes<HTMLDivElement> {
    /** info = sand panel with hairline (flashes, notes); warn = gold border; danger = brick border and text. */
    tone?: 'info' | 'warn' | 'danger';
}

const tones = {
    info: 'border-rule text-ink',
    warn: 'border-warn text-ink',
    danger: 'border-danger text-danger',
};

/** A flash or callout panel on the sand band. Copy is plain sentences; no icons, no emoji. */
export function Banner({ tone = 'info', className, ...rest }: BannerProps) {
    return <div className={cx('rounded-md bg-band border p-3 text-sm', tones[tone], className)} {...rest} />;
}
