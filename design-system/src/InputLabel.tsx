import type { LabelHTMLAttributes } from 'react';
import { cx } from './cx';

export interface InputLabelProps extends LabelHTMLAttributes<HTMLLabelElement> {}

/** Field label: small, medium weight, ink. Sits directly above its input with mt-1 on the input. */
export function InputLabel({ className, ...rest }: InputLabelProps) {
    return <label className={cx('block text-sm font-medium text-ink', className)} {...rest} />;
}
