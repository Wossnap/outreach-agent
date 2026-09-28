import type { HTMLAttributes, ReactNode } from 'react';
import { cx } from './cx';

export interface CardProps extends Omit<HTMLAttributes<HTMLDivElement>, 'title'> {
    /** Optional title row, sans 16px semibold. */
    title?: ReactNode;
    /** Something on the right of the title row, e.g. an action link or a Button size="sm". */
    action?: ReactNode;
    /** Padding on the body. Tables and lists that manage their own padding use `none`. */
    padding?: 'md' | 'lg' | 'none';
}

/** A white card on cream: 10px radius, 1px hairline, never a shadow. */
export function Card({ title, action, padding = 'md', className, children, ...rest }: CardProps) {
    const pad = { md: 'p-6', lg: 'p-8', none: '' }[padding];
    return (
        <div className={cx('bg-surface border border-rule rounded-card', className)} {...rest}>
            {(title || action) && (
                <div className={cx('flex items-center justify-between gap-3', padding === 'none' ? 'px-6 py-4 border-b border-rule' : 'px-6 pt-6')}>
                    <h3 className="text-base font-semibold text-ink">{title}</h3>
                    {action}
                </div>
            )}
            <div className={cx(pad, title && padding !== 'none' ? 'pt-3' : '')}>{children}</div>
        </div>
    );
}
