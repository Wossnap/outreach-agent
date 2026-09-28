import { StatTile } from 'outreach-agent-design-system';

/** A single tile. */
export const Single = () => <StatTile className="w-48" label="Replied" value="14" sub="7.1% of sent" />;

/** The dashboard row. */
export const Row = () => (
    <div className="grid grid-cols-3 gap-4 w-[560px]">
        <StatTile label="Sent" value="197" sub="emails delivered to Gmail" />
        <StatTile label="Opened" value="88" sub="44.7% of 197 tracked" />
        <StatTile label="Bounced" value="2" sub="1.0% of sent" />
    </div>
);

/** Nothing to show yet. */
export const Empty = () => <StatTile className="w-48" label="Spam rate (Gmail)" value="—" sub="no Postmaster data yet" />;
