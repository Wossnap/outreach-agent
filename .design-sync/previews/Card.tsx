import { Button, Card } from 'outreach-agent-design-system';

/** Plain content card. */
export const Plain = () => (
    <Card className="w-96">
        <p className="text-sm text-ink">
            A lead with no address is passed to each finder in turn until one answers. The address is then
            offered to each verifier until one commits.
        </p>
    </Card>
);

/** With a title row and an action on the right. */
export const Titled = () => (
    <Card className="w-96" title="Find an address" action={<a className="text-sm font-medium text-brand hover:underline">Order by price</a>}>
        <ol className="text-sm text-ink space-y-2">
            <li>1. Findymail</li>
            <li>2. Hunter</li>
        </ol>
    </Card>
);

/** No padding, for a list or table that manages its own rows. */
export const List = () => (
    <Card className="w-96" title="Automations" padding="none">
        <ul className="divide-y divide-rule text-sm text-ink">
            <li className="flex items-center justify-between px-6 py-3">
                <span>Agency intro</span>
                <Button size="sm" variant="secondary">Edit</Button>
            </li>
            <li className="flex items-center justify-between px-6 py-3">
                <span>Podcast guest</span>
                <Button size="sm" variant="secondary">Edit</Button>
            </li>
        </ul>
    </Card>
);
