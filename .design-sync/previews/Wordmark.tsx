import { Wordmark } from 'outreach-agent-design-system';

/** As it sits in the top bar. */
export const InNav = () => <Wordmark className="text-xl" />;

/** As it opens the login and landing pages. */
export const Landing = () => <Wordmark className="text-5xl" />;

/** Any lowercase brand name works; the gold period is the mark. */
export const OtherBrand = () => <Wordmark name="seannocode" className="text-3xl" />;
