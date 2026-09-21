<?php

namespace App\Models;

use App\Services\Sending\VerifiedEmailSwitch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A person we might email. Called a lead everywhere a person reads.
 *
 * This is the one record for somebody, however they reached us: pushed over the
 * API by another system, found by the LinkedIn scraper, or typed in. It carries
 * both who they are and how to reach them, including how far the email
 * waterfall has got with finding and confirming an address.
 */
class Contact extends Model
{
    use HasFactory;

    /*
     * Where a contact came from. Anything is accepted, because the API takes
     * submissions from systems that do not exist yet; these are the ones the
     * application itself writes.
     */
    public const SOURCE_LINKEDIN = 'linkedin';

    public const SOURCE_API = 'api';

    /*
     * How far the waterfall has got with this person, and the only answer to
     * "may we email them".
     *
     * VALID is the sole state that is sendable on its own. RISKY covers
     * catch-all domains, where the mail server accepts every address it is
     * offered and so tells us nothing about whether this mailbox exists.
     */
    public const EMAIL_PENDING = 'pending';

    public const EMAIL_FINDING = 'finding';

    public const EMAIL_VERIFYING = 'verifying';

    public const EMAIL_VALID = 'valid';

    public const EMAIL_RISKY = 'risky';

    public const EMAIL_INVALID = 'invalid';

    public const EMAIL_NOT_FOUND = 'not_found';

    protected $fillable = [
        'email', 'profile_url', 'name', 'first_name', 'last_name',
        'job_title', 'role', 'company', 'category', 'niche', 'company_url', 'domain', 'source',
        'email_status', 'email_checked_at', 'email_provider', 'extra',
    ];

    /**
     * The column has this default in the schema too, but a default living only
     * in the database is invisible until the row comes back from it: a contact
     * built in memory would report no status at all, and code asking whether it
     * may be emailed would get null rather than "we have not looked yet".
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'email_status' => self::EMAIL_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'email_checked_at' => 'datetime',
            'extra' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function emailStatuses(): array
    {
        return [
            self::EMAIL_PENDING => 'Pending',
            self::EMAIL_FINDING => 'Finding',
            self::EMAIL_VERIFYING => 'Verifying',
            self::EMAIL_VALID => 'Valid',
            self::EMAIL_RISKY => 'Risky',
            self::EMAIL_INVALID => 'Invalid',
            self::EMAIL_NOT_FOUND => 'Not found',
        ];
    }

    /**
     * Whether the waterfall has finished with this contact, one way or another.
     *
     * Pending, finding and verifying all mean the work is not done, which
     * includes one left mid-flight by a job that died. Those are picked up
     * again; the settled ones never are, so an answer is only ever paid for
     * once.
     */
    public function isEmailResolved(): bool
    {
        return in_array($this->email_status, [
            self::EMAIL_VALID,
            self::EMAIL_RISKY,
            self::EMAIL_INVALID,
            self::EMAIL_NOT_FOUND,
        ], true);
    }

    /**
     * Whether we may actually send to this person now.
     *
     * Whether an address must be confirmed by a verifier first is a switch
     * rather than a constant, so a system with no verifier configured yet can
     * relax the rule without a deploy. See VerifiedEmailSwitch.
     */
    public function isSendable(): bool
    {
        if (blank($this->email)) {
            return false;
        }

        /*
         * Never to an address something has actually told us is dead, whatever
         * the switch says.
         *
         * The switch decides how much proof is wanted before sending: on, an
         * address must be confirmed; off, an unchecked one is trusted. Neither
         * setting is an argument for sending to a mailbox a verifier rejected
         * or a message already bounced off. That is not trust, it is ignoring
         * an answer we have, and bounces cost us delivery to everybody else.
         */
        if ($this->email_status === self::EMAIL_INVALID) {
            return false;
        }

        return VerifiedEmailSwitch::isOff() || $this->email_status === self::EMAIL_VALID;
    }

    /** The same rule as isSendable, in SQL. */
    /**
     * Reachable at all: there is an address, and nothing has said it is dead.
     *
     * This is what "sendable" means with the confirmation rule relaxed, named
     * on its own so the rule can be asked about without being in force. The
     * Waterfall page uses it to say how many people relaxing the rule would
     * start, which has to be the same set that then actually starts.
     */
    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereNotNull('email')->where('email_status', '!=', self::EMAIL_INVALID);
    }

    public function scopeSendable(Builder $query): Builder
    {
        return VerifiedEmailSwitch::isOff()
            ? $query->reachable()
            : $query->whereNotNull('email')->where('email_status', self::EMAIL_VALID);
    }

    /**
     * Something a source told us that we have no column for.
     *
     * Namespaced by source, so two of them can each say "score" and mean
     * different things: extraFrom('hunter', 'score').
     */
    public function extraFrom(string $source, string $key, mixed $default = null): mixed
    {
        return data_get($this->extra, "{$source}.{$key}", $default);
    }

    /**
     * Merge in what a source sent, without disturbing what another one said.
     *
     * @param  array<string, mixed>  $values
     */
    public function rememberExtra(string $source, array $values): void
    {
        $values = array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '');

        if ($values === []) {
            return;
        }

        $extra = $this->extra ?? [];
        $extra[$source] = array_merge($extra[$source] ?? [], $values);
        $this->extra = $extra;
    }

    /**
     * What a drafter should be told about this person, beyond the named fields.
     *
     * All of it, because everything in the bag came from a caller or the
     * scraper: things worth writing an email about. What the enrichment
     * providers report is kept on their own lookup rows instead, since a
     * confidence score or an internal request id is nothing to write about.
     *
     * @return array<string, mixed>
     */
    public function extraForDrafting(): array
    {
        return $this->extra ?? [];
    }

    public function lookups(): HasMany
    {
        return $this->hasMany(EmailLookup::class)->latest('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class);
    }

    public function suppression(): ?Suppression
    {
        return Suppression::query()->where('email', $this->email)->first();
    }

    public function isSuppressed(): bool
    {
        return filled($this->email) && Suppression::isSuppressed($this->email);
    }

    /**
     * Finds the contact this data describes, or builds an unsaved one.
     *
     * Identifying somebody by email alone only works while every contact
     * arrives with one. The scraper finds people with a LinkedIn profile and no
     * address, and a caller asking us to find an address by definition has not
     * got one, so both are identities in their own right.
     *
     * Email wins when both are present. It is the thing we actually send to, it
     * survives someone changing their LinkedIn vanity URL, and it is what makes
     * two submissions from different sources the same person rather than two.
     *
     * Only identity is settled here. Filling in name, job title and the rest is
     * left to the caller, because the rule for that differs by source: the
     * scraper must never overwrite a known value with a missing one, since a
     * reaction row carries less about a person than a comment row does.
     */
    public static function findOrNewFor(
        ?string $email,
        ?string $profileUrl = null,
        ?string $name = null,
        ?string $domain = null,
    ): self {
        $email = self::normaliseEmail($email);
        $profileUrl = self::normaliseProfileUrl($profileUrl);

        if ($email !== null && $contact = self::query()->where('email', $email)->first()) {
            return $contact;
        }

        if ($profileUrl !== null && $contact = self::query()->where('profile_url', $profileUrl)->first()) {
            return $contact;
        }

        /*
         * A person named at a company, which is all a source has when it is
         * sending them precisely because nobody knows their address yet.
         *
         * Weaker than the other two: two people of the same name at one company
         * become one record. That is rare, and the alternative is a new record
         * on every submission, so a source that resends its list doubles it
         * every time.
         *
         * Matched case-insensitively, because "Sam Carter" and "sam carter" are
         * one person and only one of them can be the stored spelling.
         */
        $name = trim((string) $name) ?: null;
        $domain = self::domainFrom($domain);

        if ($name !== null && $domain !== null) {
            $contact = self::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->where('domain', $domain)
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        return new self(['email' => $email, 'profile_url' => $profileUrl]);
    }

    /**
     * A bare domain, from a domain or a URL.
     *
     * Callers send whatever they have: "acme.com", "https://www.acme.com/about",
     * "www.acme.com". A finder needs the host and nothing else.
     */
    public static function domainFrom(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        $host = parse_url(str_contains($value, '//') ? $value : "https://{$value}", PHP_URL_HOST) ?: $value;

        return Str::of($host)->after('@')->ltrim('.')->replaceMatches('/^www\./', '')->value() ?: null;
    }

    /**
     * The LinkedIn URL, but only when it points at a person.
     *
     * linkedin.com/in/sam-carter is a person, and a finder can look up an
     * address from it. linkedin.com/company/acme is a company, and asking a
     * finder for the address of a person called Acme costs a call and answers
     * nothing. Both end up in the same column, so they are told apart here.
     */
    public function linkedinProfileUrl(): ?string
    {
        return filled($this->profile_url) && str_contains($this->profile_url, '/in/')
            ? $this->profile_url
            : null;
    }

    public static function normaliseEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * One profile, one spelling.
     *
     * The same person's LinkedIn URL arrives written several ways: with and
     * without "www", with a trailing slash, and dragged out of a browser with
     * a tracking parameter on the end. Stored as sent, those were three
     * different people, and this is the identity rule for everybody the
     * scraper finds - the ones who have no address to be matched on instead.
     *
     * Only the scheme, host and path are kept. Everything after the "?" on a
     * LinkedIn profile URL describes how the reader got there, never which
     * profile it is.
     */
    public static function normaliseProfileUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        // Not a URL we can take apart. Kept as sent rather than discarded: it
        // is still something to tell two submissions apart by.
        if ($parts === false || blank($parts['host'] ?? null)) {
            return rtrim($url, '/') ?: null;
        }

        $host = mb_strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host);

        // The vanity name is case-sensitive nowhere that matters, and LinkedIn
        // itself lower-cases it.
        $path = rtrim(mb_strtolower($parts['path'] ?? ''), '/');

        return 'https://'.$host.$path;
    }

    /**
     * Reconcile `name` against `first_name`/`last_name`.
     *
     * Whatever the caller supplied wins. Anything not supplied is derived from
     * what was, but only when the stored value is blank, so re-sending one
     * part never rewrites a name that is already on record.
     *
     * `name` is kept rather than derived because not every contact splits:
     * mononyms and role addresses ("Support Team") would be mangled by it.
     *
     * @param  array<string, mixed>  $payload  what the caller sent
     * @param  array<string, mixed>  $existing  what is already stored, if anything
     * @return array<string, string>
     */
    public static function resolveNameFields(array $payload, array $existing = []): array
    {
        $given = static fn (array $source, string $key): ?string => filled($source[$key] ?? null)
            ? trim((string) $source[$key])
            : null;

        $name = $given($payload, 'name');
        $first = $given($payload, 'first_name');
        $last = $given($payload, 'last_name');

        $resolved = array_filter(
            ['name' => $name, 'first_name' => $first, 'last_name' => $last],
            fn (?string $value): bool => filled($value),
        );

        if ($name === null && ($first !== null || $last !== null) && $given($existing, 'name') === null) {
            $resolved['name'] = trim($first.' '.$last);
        }

        if ($name !== null && $first === null && $last === null
            && $given($existing, 'first_name') === null && $given($existing, 'last_name') === null) {
            // First space only: the leading token is the given name, and
            // everything after it belongs to the surname ("van der Berg").
            $resolved['first_name'] = Str::before($name, ' ');

            if (Str::contains($name, ' ')) {
                $resolved['last_name'] = trim(Str::after($name, ' '));
            }
        }

        return $resolved;
    }

    /**
     * Normalised on the way in, like the address, and for the same reason.
     *
     * This is the identity rule for everybody who arrives without an address,
     * so the stored spelling has to be the one a later push is compared
     * against. Doing it only in findOrNewFor was not enough: the ingest service
     * then assigns whatever the caller sent straight over the top, and the
     * waterfall does the same with a URL a finder returned.
     */
    protected function profileUrl(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => self::normaliseProfileUrl($value));
    }

    /**
     * Normalised on the way in, so the unique index does the job it is there
     * for: "Sam@Acme.com " and "sam@acme.com" are one person, and without this
     * they are two rows the database is happy to keep apart.
     *
     * The domain is kept in step at the same time. It is stored rather than
     * split out of the email on read because the waterfall groups work by
     * domain before any address exists, and a domain that disagrees with the
     * email it came from would be worse than no domain at all.
     *
     * A domain the caller states outright is applied after this and so wins:
     * somebody at Acme reachable on a personal address works at Acme, not at
     * their mail provider. See ContactIngestService::upsertContact.
     */
    protected function email(): Attribute
    {
        return Attribute::set(function (?string $value): array {
            $value = self::normaliseEmail($value);

            if ($value === null) {
                return ['email' => null];
            }

            return [
                'email' => $value,
                'domain' => Str::after($value, '@') ?: null,
            ];
        });
    }
}
