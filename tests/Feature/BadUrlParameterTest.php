<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A mistyped id in a URL must never look like a broken server.
 *
 * Controllers take these as integers, so a non-numeric segment reaching one is
 * a TypeError and answers with a 500. The routes constrain them to digits
 * instead, so the route simply does not match and the caller gets a 404.
 */
class BadUrlParameterTest extends TestCase
{
    use RefreshDatabase;

    /** Ids that are database keys, and the routes that accept them. */
    protected const NUMERIC_PARAMS = ['mailbox', 'message', 'reply', 'enrollment', 'step'];

    protected function headers(): array
    {
        $token = User::factory()->create()->createToken('t', ['read', 'write', 'approve'])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * Walks the real route table rather than a hand-written list, so an
     * endpoint added later is covered without anyone remembering to add it.
     */
    public function test_no_api_route_with_a_numeric_id_can_be_made_to_error(): void
    {
        $headers = $this->headers();
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $params = $route->parameterNames();
            $numeric = array_intersect($params, self::NUMERIC_PARAMS);

            if ($numeric === []) {
                continue;
            }

            // Fill every placeholder: the numeric ones with a word, the rest
            // with something harmless.
            $path = $uri;

            foreach ($params as $param) {
                $path = str_replace(
                    ['{'.$param.'}', '{'.$param.'?}'],
                    in_array($param, self::NUMERIC_PARAMS, true) ? 'not-a-number' : 'placeholder',
                    $path,
                );
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->json($method, '/'.$path, [], $headers);

                $this->assertSame(
                    404,
                    $response->status(),
                    "{$method} /{$path} returned {$response->status()} rather than 404 for a non-numeric id.",
                );

                $response->assertJsonPath('success', false);
                $checked++;
            }
        }

        // Guards against the loop silently matching nothing and passing.
        $this->assertGreaterThan(10, $checked, 'Expected to exercise more routes than this.');
    }

    public function test_the_endpoint_that_first_showed_this_returns_404(): void
    {
        $this->postJson('/api/mailboxes/not-a-number/disconnect', [], $this->headers())
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_a_valid_id_that_does_not_exist_still_returns_404(): void
    {
        $this->getJson('/api/mailboxes/999999', $this->headers())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Mailbox not found.');
    }

    public function test_routes_that_accept_a_word_are_not_broken_by_the_constraint(): void
    {
        // These deliberately take an id or an email/tag, so they must still
        // accept a non-numeric value rather than 404 on the route itself.
        $this->getJson('/api/contacts/jane.doe@acme.example', $this->headers())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Contact not found.');

        $this->getJson('/api/automations/seo-backlinks', $this->headers())
            ->assertStatus(404)
            ->assertJsonPath('message', 'Automation not found.');
    }
}
