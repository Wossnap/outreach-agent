import { InputLabel, TextInput } from 'outreach-agent-design-system';

/** Above a field. */
export const AboveField = () => (
    <div className="w-72">
        <InputLabel htmlFor="name">Name</InputLabel>
        <TextInput id="name" className="mt-1 w-full" placeholder="Type to search…" />
    </div>
);

/** Beside a checkbox, in the muted colour the app uses there. */
export const Checkbox = () => (
    <label className="flex items-center gap-2 text-sm text-ink-dim">
        <input type="checkbox" className="rounded border-rule-strong text-brand focus:ring-brand" />
        Include leads with no address found
    </label>
);
