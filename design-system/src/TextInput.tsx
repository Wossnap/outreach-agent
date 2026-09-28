import type { InputHTMLAttributes } from 'react';
import { cx } from './cx';

export interface TextInputProps extends InputHTMLAttributes<HTMLInputElement> {}

/** Text, email, password, date or search input on a white surface with a taupe border and navy focus ring. */
export function TextInput({ className, type = 'text', ...rest }: TextInputProps) {
    return (
        <input
            type={type}
            className={cx(
                'rounded-md border border-rule-strong bg-surface text-ink placeholder:text-ink-dim focus:border-brand focus:ring-brand px-3 py-2 text-sm',
                className,
            )}
            {...rest}
        />
    );
}
