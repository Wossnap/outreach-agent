import { NavLink, Wordmark } from 'outreach-agent-design-system';

/** The top bar as the app renders it: wordmark, links, one active, counts as pills. */
export const TopBar = () => (
    <nav className="bg-surface border-b border-rule w-[720px]">
        <div className="flex items-center gap-8 h-16 px-6">
            <Wordmark className="text-xl" />
            <NavLink href="#" className="h-16">Dashboard</NavLink>
            <NavLink href="#" className="h-16" badge={3}>Approvals</NavLink>
            <NavLink href="#" className="h-16" badge={1}>Replies</NavLink>
            <NavLink href="#" className="h-16" active>Leads</NavLink>
        </div>
    </nav>
);

/** Active and inactive on their own. */
export const States = () => (
    <div className="flex items-end gap-6 h-10">
        <NavLink href="#" active>Active</NavLink>
        <NavLink href="#">Inactive</NavLink>
        <NavLink href="#" badge={12}>With a count</NavLink>
    </div>
);
