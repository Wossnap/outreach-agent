import { cx } from './cx';

export interface InputErrorProps {
    /** One message or several; nothing renders when empty. */
    messages?: string | string[];
    className?: string;
}

/** Validation messages under a field, in brick. */
export function InputError({ messages, className }: InputErrorProps) {
    const list = (Array.isArray(messages) ? messages : [messages]).filter(Boolean) as string[];
    if (list.length === 0) return null;
    return (
        <ul className={cx('text-sm text-danger space-y-1', className)}>
            {list.map((m) => (
                <li key={m}>{m}</li>
            ))}
        </ul>
    );
}
