import type { SelectHTMLAttributes } from 'react';
import { cx } from './cx';

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {}

/** Native select styled like TextInput. Pass `<option>` children. */
export function Select({ className, ...rest }: SelectProps) {
    return (
        <select
            className={cx('rounded-md border border-rule-strong bg-surface text-ink focus:border-brand focus:ring-brand px-3 py-2 text-sm', className)}
            {...rest}
        />
    );
}
