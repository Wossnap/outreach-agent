import type { ButtonHTMLAttributes } from 'react';
import { cx } from './cx';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    /** primary = solid navy (one per view), secondary = ghost outline, danger = brick outline for destructive actions. */
    variant?: 'primary' | 'secondary' | 'danger';
    /** sm for toolbars and table rows. */
    size?: 'md' | 'sm';
}

const variants = {
    primary: 'border-transparent bg-brand text-brand-ink hover:bg-brand-hover focus-visible:ring-brand',
    secondary: 'border-rule-strong bg-transparent text-ink hover:border-ink-dim focus-visible:ring-brand',
    danger: 'border-danger bg-transparent text-danger hover:bg-danger/10 focus-visible:ring-danger',
};

/**
 * Sentence-case button, 6px radius, 240ms motion. Buttons are never uppercase.
 */
export function Button({ variant = 'primary', size = 'md', className, type = 'button', ...rest }: ButtonProps) {
    return (
        <button
            type={type}
            className={cx(
                'inline-flex items-center justify-center gap-2 rounded-md border text-sm font-semibold active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-page disabled:opacity-50 transition',
                size === 'sm' ? 'px-3 py-1.5' : 'px-4 py-2',
                variants[variant],
                className,
            )}
            {...rest}
        />
    );
}
