import { SegmentedToggle } from 'outreach-agent-design-system';

const windows = [
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 90 days' },
    { value: 'all', label: 'All time' },
];

/** The dashboard's date window. */
export const DateWindow = () => <SegmentedToggle options={windows} value="30" />;

/** Two choices. */
export const Pair = () => (
    <SegmentedToggle
        options={[
            { value: 'compact', label: 'Compact' },
            { value: 'edit', label: 'Edit view' },
        ]}
        value="compact"
    />
);
