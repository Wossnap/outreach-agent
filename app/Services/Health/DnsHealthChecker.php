<?php

namespace App\Services\Health;

use App\Models\Domain;
use App\Models\HealthCheck;
use App\Support\Dns\DnsResolver;
use Illuminate\Support\Str;

/**
 * Checks the DNS auth records a sending domain must carry for Gmail-API
 * sending to authenticate: SPF (with Google's include), DKIM (Workspace
 * selector), DMARC. IP reputation is not checked — mail leaves Google's IPs.
 */
class DnsHealthChecker
{
    public function __construct(
        protected DnsResolver $resolver,
    ) {}

    public function check(Domain $domain): void
    {
        $spf = $this->checkSpf($domain->name);
        $dkim = $this->checkDkim($domain);
        $dmarc = $this->checkDmarc($domain->name);

        foreach (['spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc] as $type => $result) {
            HealthCheck::query()->create([
                'checkable_type' => $domain->getMorphClass(),
                'checkable_id' => $domain->id,
                'check_type' => $type,
                // monitoring and enforcing are both healthy DMARC states, and
                // absent is informational rather than a failure.
                'status' => match ($result['status']) {
                    'ok', 'monitoring', 'enforcing' => HealthCheck::STATUS_OK,
                    'warn', 'absent' => HealthCheck::STATUS_WARN,
                    default => HealthCheck::STATUS_FAIL,
                },
                'detail' => $result,
                'checked_at' => now(),
            ]);
        }

        $domain->update([
            'spf_status' => $spf['status'],
            'dkim_status' => $dkim['status'],
            'dmarc_status' => $dmarc['status'],
            'last_dns_checked_at' => now(),
            'health_status' => $this->overallStatus($domain, $spf, $dkim, $dmarc),
        ]);
    }

    /**
     * @return array{status: string, record: ?string, note: string}
     */
    protected function checkSpf(string $domain): array
    {
        $spf = collect($this->resolver->txtRecords($domain))
            ->first(fn (string $txt) => Str::startsWith(mb_strtolower(trim($txt)), 'v=spf1'));

        if (! $spf) {
            return ['status' => 'missing', 'record' => null, 'note' => 'No SPF record found.'];
        }

        if (! str_contains(mb_strtolower($spf), 'include:_spf.google.com')) {
            return ['status' => 'warn', 'record' => $spf, 'note' => 'SPF exists but does not include _spf.google.com — Gmail-sent mail may fail SPF alignment.'];
        }

        return ['status' => 'ok', 'record' => $spf, 'note' => 'SPF includes Google.'];
    }

    /**
     * @return array{status: string, record: ?string, note: string, selector: string}
     */
    protected function checkDkim(Domain $domain): array
    {
        $selector = $domain->dkimSelector();
        $host = "{$selector}._domainkey.{$domain->name}";

        $dkim = collect($this->resolver->txtRecords($host))
            ->first(fn (string $txt) => str_contains(mb_strtolower($txt), 'v=dkim1') || str_contains(mb_strtolower($txt), 'k=rsa'));

        if (! $dkim) {
            return ['status' => 'missing', 'record' => null, 'selector' => $selector, 'note' => "No DKIM record at {$host}. In Google Admin: Apps → Gmail → Authenticate email."];
        }

        return ['status' => 'ok', 'record' => Str::limit($dkim, 120), 'selector' => $selector, 'note' => 'DKIM record present.'];
    }

    /**
     * @return array{status: string, record: ?string, note: string}
     */
    protected function checkDmarc(string $domain): array
    {
        $dmarc = collect($this->resolver->txtRecords("_dmarc.{$domain}"))
            ->first(fn (string $txt) => Str::startsWith(mb_strtolower(trim($txt)), 'v=dmarc1'));

        // DMARC does not gate delivery, so none of these outcomes is a fault.
        //
        // A missing record is worth knowing about but is not a problem to fix
        // before sending: Gmail asks for DMARC only above 5,000 messages a day,
        // and even then p=none satisfies it.
        //
        // p=none is the configuration both the standard and Google tell you to
        // start with, so flagging it would be flagging correct work. Tightening
        // it early is what breaks legitimate mail, and the reports that justify
        // tightening take a week or more to gather.
        if (! $dmarc) {
            return ['status' => 'absent', 'record' => null, 'note' => 'No DMARC record. Not required to send; worth adding to receive reports on who sends as this domain.'];
        }

        // The policy is carried in the status rather than derived later from
        // the stored record, so reading it costs no extra query and the label
        // cannot disagree with the verdict.
        if (preg_match('/p\s*=\s*none/i', $dmarc)) {
            return ['status' => 'monitoring', 'record' => $dmarc, 'note' => 'DMARC present, policy p=none. The correct setting until a week of reports confirms every legitimate sender passes.'];
        }

        return ['status' => 'enforcing', 'record' => $dmarc, 'note' => 'DMARC present and enforcing.'];
    }

    /**
     * The domain's overall verdict.
     *
     * Only things that actually stop mail authenticating count against it.
     * A page that goes amber for a correctly configured domain teaches people
     * to ignore amber, which costs more than it saves on the day it matters.
     */
    protected function overallStatus(Domain $domain, array $spf, array $dkim, array $dmarc): string
    {
        if ($domain->dnsbl_listed) {
            return Domain::HEALTH_CRITICAL;
        }

        // Without either of these, mail from the domain is unauthenticated.
        if ($spf['status'] === 'missing' || $dkim['status'] === 'missing') {
            return Domain::HEALTH_CRITICAL;
        }

        // An SPF record that omits Google means Gmail-sent mail fails the
        // check, which is a real fault rather than a preference.
        if ($spf['status'] === 'warn') {
            return Domain::HEALTH_WARNING;
        }

        return Domain::HEALTH_HEALTHY;
    }
}
