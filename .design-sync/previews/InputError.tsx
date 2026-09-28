import { InputError, InputLabel, TextInput } from 'outreach-agent-design-system';

/** One validation message under its field. */
export const Single = () => (
    <div className="w-72">
        <InputLabel htmlFor="email">Email</InputLabel>
        <TextInput id="email" className="mt-1 w-full" defaultValue="sam@" />
        <InputError className="mt-2" messages="The email field must be a valid email address." />
    </div>
);

/** Several messages stack. */
export const Multiple = () => (
    <div className="w-72">
        <InputLabel htmlFor="password">Password</InputLabel>
        <TextInput id="password" type="password" className="mt-1 w-full" defaultValue="abc" />
        <InputError
            className="mt-2"
            messages={['The password must be at least 8 characters.', 'The password confirmation does not match.']}
        />
    </div>
);
