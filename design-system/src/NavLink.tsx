import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { cx } from './cx';
import { Pill } from './Pill';

export interface NavLinkProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
    /** The current page gets a navy underline and ink text. */
    active?: boolean;
    /** A count shown as a small navy pill after the label, e.g. pending approvals. */
    badge?: ReactNode;
}

/** Top-bar navigation link. Sits in a 64px-tall bar on a white surface with a hairline under it. */
export function NavLink({ active = false, badge, className, children, ...rest }: NavLinkProps) {
    return (
        <a
            className={cx(
                'inline-flex items-center gap-1 px-1 pt-1 border-b-2 text-sm font-medium leading-5 focus:outline-none transition',
                active ? 'border-brand text-ink' : 'border-transparent text-ink-dim hover:text-ink hover:border-rule-strong',
                className,
            )}
            {...rest}
        >
            {children}
            {badge !== undefined && badge !== null && <Pill tone="good" className="ms-1">{badge}</Pill>}
        </a>
    );
}
