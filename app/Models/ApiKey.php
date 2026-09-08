<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum personal access token, under a name that says what it is for here.
 *
 * Sanctum's own model is fine; this exists so the dashboard can present keys as
 * keys, and so the abilities we actually use are written down in one place
 * rather than repeated in the middleware, the settings page and the docs.
 */
class ApiKey extends PersonalAccessToken
{
    /** Read anything. */
    public const ABILITY_READ = 'read';

    /** Create and change data, including pushing contacts. */
    public const ABILITY_WRITE = 'write';

    /** Approve a draft so it sends. Also gated by config. */
    public const ABILITY_APPROVE = 'approve';

    protected $table = 'personal_access_tokens';

    /**
     * The abilities a new key may be given, and what each one allows.
     *
     * @return array<string, string>
     */
    public static function abilities(): array
    {
        return [
            self::ABILITY_READ => 'Read contacts, replies, opt-outs, drafts, stats and activity',
            self::ABILITY_WRITE => 'Push contacts, edit and reject drafts, manage automations and mailboxes',
            self::ABILITY_APPROVE => 'Approve a draft so it sends (also needs enabling in config)',
        ];
    }
}
