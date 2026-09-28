import { cx } from './cx';

export interface WordmarkProps {
    /** The brand name, rendered lowercase. Defaults to the app name. */
    name?: string;
    /** Sets the size, e.g. `text-xl` in the nav, `text-5xl` on a landing page. */
    className?: string;
}

/**
 * The wordmark is the logo: lowercase serif with the trailing period in gold.
 * That period is the one gold mark allowed on a page, so never add another.
 */
export function Wordmark({ name = 'outreach agent', className }: WordmarkProps) {
    return (
        <span className={cx('font-display font-semibold tracking-tight text-ink', className)}>
            {name.toLowerCase()}
            <span className="text-accent">.</span>
        </span>
    );
}
