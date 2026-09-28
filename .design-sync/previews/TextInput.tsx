import { InputLabel, TextInput } from 'outreach-agent-design-system';

/** A labelled search field as the Filters panel uses it. */
export const Search = () => (
    <div className="w-72">
        <InputLabel htmlFor="email">Email</InputLabel>
        <TextInput id="email" className="mt-1 w-full" placeholder="Type to search…" />
    </div>
);

/** Filled in. */
export const Filled = () => (
    <div className="w-72">
        <InputLabel htmlFor="company">Company</InputLabel>
        <TextInput id="company" className="mt-1 w-full" defaultValue="Northwind Studio" />
    </div>
);

/** Other input types share the styling. */
export const Types = () => (
    <div className="flex gap-3">
        <TextInput type="date" className="w-44" defaultValue="2026-09-28" />
        <TextInput type="password" className="w-44" defaultValue="hunter2hunter2" />
    </div>
);
