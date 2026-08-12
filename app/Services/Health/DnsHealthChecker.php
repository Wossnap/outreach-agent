<?php

namespace App\Services\Health;

use App\Models\Domain;
use App\Models\HealthCheck;
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
                'status' => $result['status'] === 'ok' ? HealthCheck::STATUS_OK : ($result['status'] === 'warn' ? HealthCheck::STATUS_WARN : HealthCheck::STATUS_FAIL),
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

        if (! $dmarc) {
            return ['status' => 'missing', 'record' => null, 'note' => 'No DMARC record found.'];
        }

        if (preg_match('/p\s*=\s*none/i', $dmarc)) {
            return ['status' => 'warn', 'record' => $dmarc, 'note' => 'DMARC policy is p=none — fine while warming, move to quarantine once stable.'];
        }

        return ['status' => 'ok', 'record' => $dmarc, 'note' => 'DMARC enforcing.'];
    }

    protected function overallStatus(Domain $domain, array $spf, array $dkim, array $dmarc): string
    {
        if ($domain->dnsbl_listed) {
            return Domain::HEALTH_CRITICAL;
        }

        if ($spf['status'] === 'missing' || $dkim['status'] === 'missing') {
            return Domain::HEALTH_CRITICAL;
        }

        if ($spf['status'] === 'warn' || $dmarc['status'] !== 'ok') {
            return Domain::HEALTH_WARNING;
        }

        return Domain::HEALTH_HEALTHY;
    }
}
