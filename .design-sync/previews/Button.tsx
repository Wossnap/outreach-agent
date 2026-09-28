import { Button } from 'outreach-agent-design-system';

/** The three variants side by side: one primary per view, ghost for the rest, brick for destructive. */
export const Variants = () => (
    <div className="flex items-center gap-3">
        <Button variant="primary">Approve</Button>
        <Button variant="secondary">Edit view</Button>
        <Button variant="danger">Reject</Button>
    </div>
);

/** Small size for toolbars and table rows. */
export const Small = () => (
    <div className="flex items-center gap-2">
        <Button size="sm">Check the addresses again</Button>
        <Button size="sm" variant="secondary">Filters</Button>
        <Button size="sm" variant="danger">Disconnect</Button>
    </div>
);

/** Disabled state: half opacity, no colour shift. */
export const Disabled = () => (
    <div className="flex items-center gap-3">
        <Button disabled>Approve selected (0)</Button>
        <Button variant="danger" disabled>Reject selected (0)</Button>
    </div>
);
