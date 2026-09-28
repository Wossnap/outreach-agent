import { cx } from './cx';

export interface SegmentedToggleOption {
    value: string;
    label: string;
}

export interface SegmentedToggleProps {
    options: SegmentedToggleOption[];
    /** The selected option's value. */
    value: string;
    onChange?: (value: string) => void;
    className?: string;
}

/** A row of mutually exclusive choices, e.g. a date window. The selected one is solid navy. */
export function SegmentedToggle({ options, value, onChange, className }: SegmentedToggleProps) {
    return (
        <div className={cx('inline-flex items-center gap-1 rounded-md border border-rule p-1 bg-surface', className)}>
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    onClick={() => onChange?.(o.value)}
                    className={cx(
                        'px-3 py-1.5 text-sm font-medium rounded transition',
                        o.value === value ? 'bg-brand text-brand-ink' : 'text-ink-dim hover:bg-band',
                    )}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}
