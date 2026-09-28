import { Pill } from 'outreach-agent-design-system';

/** The four tones, in the order a table column would show them. */
export const Tones = () => (
    <div className="flex items-center gap-2">
        <Pill tone="good">Valid</Pill>
        <Pill tone="warn">Risky</Pill>
        <Pill tone="danger">Invalid</Pill>
        <Pill tone="neutral">Pending</Pill>
    </div>
);

/** Automation tags in a Leads row: neutral pills, wrapped. */
export const Tags = () => (
    <div className="flex flex-wrap gap-1 max-w-[220px]">
        <Pill>agency-intro</Pill>
        <Pill>podcast-guest</Pill>
        <Pill>seo-backlinks</Pill>
    </div>
);

/** A count badge beside a nav label. */
export const Count = () => (
    <span className="inline-flex items-center text-sm font-medium text-ink">
        Approvals <Pill tone="good" className="ms-1">3</Pill>
    </span>
);
