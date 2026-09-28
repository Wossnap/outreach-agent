import { InputLabel, Select } from 'outreach-agent-design-system';

/** The Suppressed filter. */
export const Suppressed = () => (
    <div className="w-72">
        <InputLabel htmlFor="suppressed">Suppressed</InputLabel>
        <Select id="suppressed" className="mt-1 w-full" defaultValue="">
            <option value="">Any</option>
            <option value="yes">Suppressed only</option>
            <option value="no">Not suppressed</option>
        </Select>
    </div>
);

/** A compact select in a toolbar. */
export const Compact = () => (
    <Select defaultValue="error" className="w-40">
        <option value="">All levels</option>
        <option value="info">Info</option>
        <option value="warning">Warning</option>
        <option value="error">Error</option>
    </Select>
);
