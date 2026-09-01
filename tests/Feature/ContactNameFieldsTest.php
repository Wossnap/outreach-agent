<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactNameFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Automation::factory()->create(['tag' => 'leads']);
    }

    protected function headers(): array
    {
        $token = User::factory()->create()->createToken('test', ['write'])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_name_alone_is_split_into_first_and_last(): void
    {
        $this->postJson('/api/contacts', [
            'email' => 'jane@example.com', 'name' => 'Jane Doe', 'tags' => ['leads'],
        ], $this->headers())->assertOk();

        $contact = Contact::query()->where('email', 'jane@example.com')->first();

        $this->assertSame('Jane Doe', $contact->name);
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
    }

    public function test_parts_alone_are_joined_into_name(): void
    {
        $this->postJson('/api/contacts', [
            'email' => 'jane@example.com', 'first_name' => 'Jane', 'last_name' => 'Doe', 'tags' => ['leads'],
        ], $this->headers())->assertOk();

        $contact = Contact::query()->where('email', 'jane@example.com')->first();

        $this->assertSame('Jane Doe', $contact->name);
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
    }

    public function test_all_three_are_stored_exactly_as_sent(): void
    {
        $this->postJson('/api/contacts', [
            'email' => 'bob@example.com',
            'name' => 'Robert Smith Jr',
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'tags' => ['leads'],
        ], $this->headers())->assertOk();

        $contact = Contact::query()->where('email', 'bob@example.com')->first();

        $this->assertSame('Robert Smith Jr', $contact->name);
        $this->assertSame('Bob', $contact->first_name);
        $this->assertSame('Smith', $contact->last_name);
    }

    public function test_a_mononym_leaves_the_last_name_empty(): void
    {
        $this->postJson('/api/contacts', [
            'email' => 'support@example.com', 'name' => 'Support', 'tags' => ['leads'],
        ], $this->headers())->assertOk();

        $contact = Contact::query()->where('email', 'support@example.com')->first();

        $this->assertSame('Support', $contact->name);
        $this->assertSame('Support', $contact->first_name);
        $this->assertNull($contact->last_name);
    }

    public function test_everything_after_the_first_space_is_the_surname(): void
    {
        $this->postJson('/api/contacts', [
            'email' => 'k@example.com', 'name' => 'Klaas van der Berg', 'tags' => ['leads'],
        ], $this->headers())->assertOk();

        $contact = Contact::query()->where('email', 'k@example.com')->first();

        $this->assertSame('Klaas', $contact->first_name);
        $this->assertSame('van der Berg', $contact->last_name);
    }

    public function test_resending_one_part_does_not_rewrite_a_stored_name(): void
    {
        Contact::query()->create([
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $this->postJson('/api/contacts', [
            'email' => 'jane@example.com', 'first_name' => 'Janet', 'tags' => ['leads'],
        ], $this->headers())->assertOk();

        $contact = Contact::query()->where('email', 'jane@example.com')->first();

        // The supplied part changes; the stored full name is left alone rather
        // than being rebuilt as "Janet".
        $this->assertSame('Janet', $contact->first_name);
        $this->assertSame('Jane Doe', $contact->name);
        $this->assertSame('Doe', $contact->last_name);
    }

    public function test_the_parts_are_returned_by_the_read_api(): void
    {
        Contact::query()->create([
            'email' => 'jane@example.com', 'name' => 'Jane Doe',
            'first_name' => 'Jane', 'last_name' => 'Doe',
        ]);

        $token = User::factory()->create()->createToken('r', ['read'])->plainTextToken;

        $this->getJson('/api/contacts', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.0.first_name', 'Jane')
            ->assertJsonPath('data.0.last_name', 'Doe');
    }
}
