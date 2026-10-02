<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairLookupStatusesTest extends TestCase
{
    use RefreshDatabase;

    private function settled(string $status, array $lookups, ?string $email = null): Contact
    {
        $settledAt = now()->subDays(8);
        $contact = Contact::factory()->create(['email' => $email, 'email_status' => $status, 'email_checked_at' => $settledAt]);

        foreach ($lookups as [$kind, $result, $minutesBefore]) {
            $lookup = EmailLookup::create([
                'contact_id' => $contact->id, 'provider_name' => 'Hunter', 'driver' => 'hunter',
                'kind' => $kind, 'result' => $result, 'cost' => 0,
            ]);
            $lookup->forceFill(['created_at' => $settledAt->copy()->subMinutes($minutesBefore)])->saveQuietly();
        }

        return $contact;
    }

    private function stuck(string $status, int $minutesAgo, ?string $email = null): Contact
    {
        $contact = Contact::factory()->create(['email' => $email, 'email_status' => $status]);
        Contact::query()->whereKey($contact->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $contact;
    }

    public function test_it_reports_without_changing_anything_unless_told_to_apply(): void
    {
        $notFound = $this->settled(Contact::EMAIL_NOT_FOUND, [['find', 'error', 0]]);

        $this->artisan('enrichment:repair-statuses')
            ->expectsOutputToContain('1 lead(s) would move to waiting to retry. Nothing changed')
            ->assertSuccessful();

        $this->assertSame(Contact::EMAIL_NOT_FOUND, $notFound->fresh()->email_status);
    }

    public function test_applied_it_moves_only_the_leads_a_failure_or_a_dead_lookup_left_behind(): void
    {
        $notFound = $this->settled(Contact::EMAIL_NOT_FOUND, [['find', 'error', 0]]);
        $risky = $this->settled(Contact::EMAIL_RISKY, [['verify', 'catch_all', 1], ['verify', 'error', 0]], 'sam@acme.com');
        $finding = $this->stuck(Contact::EMAIL_FINDING, 60, 'found@acme.com');

        $answered = $this->settled(Contact::EMAIL_NOT_FOUND, [['find', 'nothing', 0]]);
        $oldFailure = $this->settled(Contact::EMAIL_NOT_FOUND, [['find', 'error', 60 * 24], ['find', 'nothing', 0]]);
        $wrongKind = $this->settled(Contact::EMAIL_RISKY, [['find', 'error', 1], ['verify', 'catch_all', 0]], 'kim@acme.com');
        $runningNow = $this->stuck(Contact::EMAIL_FINDING, 1, 'busy@acme.com');

        $this->artisan('enrichment:repair-statuses --apply')
            ->expectsOutputToContain('3 lead(s) moved to waiting to retry.')
            ->assertSuccessful();

        foreach ([$notFound, $risky, $finding] as $moved) {
            $this->assertSame(Contact::EMAIL_WAITING, $moved->fresh()->email_status);
        }
        $this->assertSame('found@acme.com', $finding->fresh()->email);

        $this->assertSame(Contact::EMAIL_NOT_FOUND, $answered->fresh()->email_status);
        $this->assertSame(Contact::EMAIL_NOT_FOUND, $oldFailure->fresh()->email_status);
        $this->assertSame(Contact::EMAIL_RISKY, $wrongKind->fresh()->email_status);
        $this->assertSame(Contact::EMAIL_FINDING, $runningNow->fresh()->email_status);
    }
}
