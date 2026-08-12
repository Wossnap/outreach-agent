<?php

namespace Tests\Feature;

use App\Livewire\Contacts\Index;
use App\Models\Contact;
use App\Models\Enrollment;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContactsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_filters_by_email_and_company_combined(): void
    {
        Contact::factory()->create(['email' => 'jane@acme.com', 'company' => 'Acme']);
        Contact::factory()->create(['email' => 'john@acme.com', 'company' => 'Acme']);
        Contact::factory()->create(['email' => 'jane@other.com', 'company' => 'Other']);

        Livewire::test(Index::class)
            ->set('email', 'jane')
            ->set('company', 'Acme')
            ->assertSee('jane@acme.com')
            ->assertDontSee('john@acme.com')
            ->assertDontSee('jane@other.com');
    }

    public function test_filters_by_enrollment_status(): void
    {
        $replied = Contact::factory()->create(['email' => 'replied@x.com']);
        Enrollment::factory()->create(['contact_id' => $replied->id, 'status' => Enrollment::STATUS_STOPPED_REPLY]);
        Contact::factory()->create(['email' => 'fresh@x.com']);

        Livewire::test(Index::class)
            ->set('enrollmentStatuses', [Enrollment::STATUS_STOPPED_REPLY])
            ->assertSee('replied@x.com')
            ->assertDontSee('fresh@x.com');
    }

    public function test_filters_by_suppressed(): void
    {
        Contact::factory()->create(['email' => 'gone@x.com']);
        Suppression::suppress('gone@x.com', Suppression::REASON_UNSUBSCRIBED);
        Contact::factory()->create(['email' => 'here@x.com']);

        Livewire::test(Index::class)
            ->set('suppressed', 'yes')
            ->assertSee('gone@x.com')
            ->assertDontSee('here@x.com');

        Livewire::test(Index::class)
            ->set('suppressed', 'no')
            ->assertSee('here@x.com')
            ->assertDontSee('gone@x.com');
    }

    public function test_sorts_by_allowed_column_and_toggles_direction(): void
    {
        Contact::factory()->create(['email' => 'a@x.com']);
        Contact::factory()->create(['email' => 'z@x.com']);

        $component = Livewire::test(Index::class)->call('sortBy', 'email');
        $this->assertSame('asc', $component->get('sortDirection'));
        $component->assertSeeInOrder(['a@x.com', 'z@x.com']);

        $component->call('sortBy', 'email');
        $this->assertSame('desc', $component->get('sortDirection'));
        $component->assertSeeInOrder(['z@x.com', 'a@x.com']);
    }

    public function test_rejects_unlisted_sort_columns(): void
    {
        $component = Livewire::test(Index::class)->call('sortBy', 'custom');

        $this->assertSame('', $component->get('sortField'));
    }

    public function test_clear_filters_resets_everything(): void
    {
        $component = Livewire::test(Index::class)
            ->set('email', 'x')
            ->set('suppressed', 'yes')
            ->set('sources', ['app-1']);

        $this->assertSame(3, $component->instance()->activeFilterCount());

        $component->call('clearFilters');

        $this->assertSame(0, $component->instance()->activeFilterCount());
    }

    public function test_manual_suppress_stops_active_enrollments(): void
    {
        $contact = Contact::factory()->create(['email' => 'target@x.com']);
        $enrollment = Enrollment::factory()->create(['contact_id' => $contact->id]);

        Livewire::test(Index::class)->call('suppress', $contact->id);

        $this->assertTrue(Suppression::isSuppressed('target@x.com'));
        $this->assertSame(Enrollment::STATUS_STOPPED_SUPPRESSED, $enrollment->fresh()->status);
    }
}
