<?php

namespace App\Services\Postmaster;

use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\PostmasterStat;
use App\Services\Gmail\GmailClientFactory;
use Carbon\CarbonImmutable;
use Google\Service\Exception as GoogleException;
use Google\Service\PostmasterTools;
use Google\Service\PostmasterTools\BaseMetric;
use Google\Service\PostmasterTools\Date;
use Google\Service\PostmasterTools\DateRange;
use Google\Service\PostmasterTools\DateRanges;
use Google\Service\PostmasterTools\DomainStat;
use Google\Service\PostmasterTools\MetricDefinition;
use Google\Service\PostmasterTools\QueryDomainStatsRequest;
use Google\Service\PostmasterTools\TimeQuery;
use RuntimeException;

/**
 * Pulls Gmail Postmaster Tools figures for a sending domain.
 *
 * Postmaster is the only place "went to spam" is knowable at all, and what it
 * reports is the share of delivered mail that Gmail users marked as spam. It
 * reports nothing for a domain the Google account has not verified at
 * postmaster.google.com, and nothing for a day with too little Gmail traffic
 * to be meaningful, so "no data" is a normal answer and is recorded as such
 * rather than as zero.
 */
class PostmasterSync
{
    public const METRICS = [
        BaseMetric::STANDARD_METRIC_SPAM_RATE,
        BaseMetric::STANDARD_METRIC_AUTH_SUCCESS_RATE,
        BaseMetric::STANDARD_METRIC_DELIVERY_ERROR_RATE,
    ];

    public function __construct(
        protected GmailClientFactory $clients,
    ) {}

    /**
     * @return array{domain: string, ok: bool, text: string}
     */
    public function sync(Domain $domain, ?int $days = null): array
    {
        $mailbox = $this->credentialFor($domain);

        if (! $mailbox) {
            return $this->failed($domain, 'No connected mailbox has the Postmaster scope; reconnect one from the Mailboxes page.');
        }

        // Postmaster lags by a day or three, so every later run re-asks for
        // the last week and upserts; only the first run reaches further back.
        $days ??= $domain->postmaster_synced_at ? 7 : (int) config('outreach.postmaster.lookback_days');

        try {
            $service = $this->clients->postmasterFor($mailbox);
            $name = 'domains/'.$domain->name;

            $domain->postmaster_verification = $service->domains->get($name)->getVerificationState();
            $domain->postmaster_compliance = $this->complianceOf($service, $name);

            $rows = $this->fetch($service, $name, $days);

            if ($rows !== []) {
                PostmasterStat::query()->upsert(
                    array_map(fn (array $row) => $row + ['domain_id' => $domain->id, 'created_at' => now(), 'updated_at' => now()], $rows),
                    ['domain_id', 'date'],
                    ['spam_rate', 'auth_success_rate', 'delivery_error_rate', 'raw', 'updated_at'],
                );
            }

            $domain->forceFill(['postmaster_synced_at' => now(), 'postmaster_error' => null])->save();

            $text = $rows === []
                ? 'Postmaster has no figures for the last '.$days.' days. That is normal for a domain with little Gmail traffic.'
                : count($rows).' day(s) of Gmail figures.';

            return ['domain' => $domain->name, 'ok' => true, 'text' => $text];
        } catch (GoogleException $e) {
            $code = $e->getCode();

            $text = in_array($code, [403, 404], true)
                ? "Not registered or not verified in Postmaster Tools for {$mailbox->email} (HTTP {$code}). Add and verify the domain at postmaster.google.com with that account."
                : "Postmaster API error (HTTP {$code}): ".mb_substr($e->getMessage(), 0, 300);

            return $this->failed($domain, $text);
        } catch (RuntimeException $e) {
            return $this->failed($domain, $e->getMessage());
        }
    }

    /**
     * The mailbox whose Google account should be asked.
     *
     * Access belongs to the account, not the domain, so one on the domain is
     * preferred and any other connected account is tried before giving up.
     */
    protected function credentialFor(Domain $domain): ?Mailbox
    {
        $scoped = fn ($query) => $query
            ->where('status', '!=', Mailbox::STATUS_DISCONNECTED)
            ->whereNotNull('google_refresh_token')
            ->get()
            ->first(fn (Mailbox $m) => $m->hasScope(PostmasterTools::POSTMASTER_TRAFFIC_READONLY));

        return $scoped($domain->mailboxes()) ?? $scoped(Mailbox::query());
    }

    /** @return array<string, mixed>|null */
    protected function complianceOf(PostmasterTools $service, string $name): ?array
    {
        try {
            $status = $service->domains->getComplianceStatus($name);

            return json_decode(json_encode($status->toSimpleObject()), true) ?: null;
        } catch (GoogleException) {
            // Newer than the rest of the API; a domain without it still has stats.
            return null;
        }
    }

    /**
     * One row per day, keyed by date, for the requested window ending today.
     *
     * @return array<int, array{date: string, spam_rate: ?float, auth_success_rate: ?float, delivery_error_rate: ?float, raw: string}>
     */
    protected function fetch(PostmasterTools $service, string $name, int $days): array
    {
        $end = CarbonImmutable::today(config('outreach.timezone'));
        $start = $end->subDays($days);

        $request = new QueryDomainStatsRequest;
        $request->setTimeQuery($this->timeQuery($start, $end));
        $request->setMetricDefinitions(array_map(function (string $metric): MetricDefinition {
            $base = new BaseMetric;
            $base->setStandardMetric($metric);
            $definition = new MetricDefinition;
            $definition->setName($metric);
            $definition->setBaseMetric($base);

            return $definition;
        }, self::METRICS));
        $request->setAggregationGranularity(QueryDomainStatsRequest::AGGREGATION_GRANULARITY_DAILY);
        $request->setPageSize(500);

        $byDate = [];

        do {
            $response = $service->domains_domainStats->query($name, $request);

            foreach ($response->getDomainStats() ?? [] as $stat) {
                $date = $this->dateOf($stat);

                if ($date === null) {
                    continue;
                }

                $byDate[$date][$stat->getMetric()] = $this->valueOf($stat);
            }

            $request->setPageToken($response->getNextPageToken());
        } while ($response->getNextPageToken());

        ksort($byDate);

        return array_map(fn (string $date, array $metrics) => [
            'date' => $date,
            'spam_rate' => $metrics[BaseMetric::STANDARD_METRIC_SPAM_RATE] ?? null,
            'auth_success_rate' => $metrics[BaseMetric::STANDARD_METRIC_AUTH_SUCCESS_RATE] ?? null,
            'delivery_error_rate' => $metrics[BaseMetric::STANDARD_METRIC_DELIVERY_ERROR_RATE] ?? null,
            'raw' => json_encode($metrics),
        ], array_keys($byDate), $byDate);
    }

    protected function timeQuery(CarbonImmutable $start, CarbonImmutable $end): TimeQuery
    {
        $range = new DateRange;
        $range->setStart($this->date($start));
        $range->setEnd($this->date($end));

        $ranges = new DateRanges;
        $ranges->setDateRanges([$range]);

        $query = new TimeQuery;
        $query->setDateRanges($ranges);

        return $query;
    }

    protected function date(CarbonImmutable $day): Date
    {
        $date = new Date;
        $date->setYear($day->year);
        $date->setMonth($day->month);
        $date->setDay($day->day);

        return $date;
    }

    protected function dateOf(DomainStat $stat): ?string
    {
        $date = $stat->getDate();

        if (! $date || ! $date->getYear()) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $date->getYear(), $date->getMonth(), $date->getDay());
    }

    protected function valueOf(DomainStat $stat): ?float
    {
        $value = $stat->getValue();

        if (! $value) {
            return null;
        }

        $number = $value->getDoubleValue() ?? $value->getFloatValue() ?? $value->getIntValue();

        return $number === null ? null : (float) $number;
    }

    /**
     * @return array{domain: string, ok: bool, text: string}
     */
    protected function failed(Domain $domain, string $text): array
    {
        // Logged once per distinct problem, not once per day it persists.
        if ($domain->postmaster_error !== $text) {
            ActivityLog::record(
                event: 'postmaster_sync_failed',
                message: "{$domain->name}: {$text}",
                level: ActivityLog::LEVEL_WARNING,
                subject: $domain,
            );
        }

        $domain->forceFill(['postmaster_error' => $text])->save();

        return ['domain' => $domain->name, 'ok' => false, 'text' => $text];
    }
}
