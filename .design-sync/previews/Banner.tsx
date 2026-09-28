import { Banner } from 'outreach-agent-design-system';

/** A status flash after an action. */
export const Info = () => (
    <Banner className="w-[520px]">3 leads sent back through the waterfall.</Banner>
);

/** Something to watch, gold border. */
export const Warn = () => (
    <Banner tone="warn" className="w-[520px]">
        Copy this key now. It is shown once and cannot be recovered.
    </Banner>
);

/** Something went wrong, brick. */
export const Danger = () => (
    <Banner tone="danger" className="w-[520px]">
        Google refused the token refresh. Reconnect the mailbox to keep sending.
    </Banner>
);
