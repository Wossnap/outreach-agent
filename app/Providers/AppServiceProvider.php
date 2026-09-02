<?php

namespace App\Providers;

use App\Services\Drafting\AnthropicDrafter;
use App\Services\Drafting\Drafter;
use App\Services\Drafting\MockDrafter;
use App\Services\Health\DnsResolver;
use App\Services\Health\PhpDnsResolver;
use Illuminate\Support\ServiceProvider;
use Knuckles\Scribe\Scribe;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, PhpDnsResolver::class);

        $this->app->bind(Drafter::class, fn () => config('services.anthropic.drafter') === 'mock'
            ? new MockDrafter
            : new AnthropicDrafter);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->prettyPrintPostmanBodies();
    }

    /**
     * Tidy the generated Postman collection so it is pleasant to use.
     *
     * Two things the generator leaves rough:
     *
     * Request bodies are written on a single line, so anything with more than
     * a couple of fields has to be reformatted by hand before it can be read
     * or edited.
     *
     * The URL keeps every filter, including the switched-off ones, so it
     * arrives as "?email=&company=&q=" and the address bar looks broken.
     *
     * Hooked into generation rather than left as a follow-up command, so it
     * cannot be forgotten. Guarded by class_exists because the generator is a
     * development dependency and is absent in production, where this provider
     * still loads.
     */
    protected function prettyPrintPostmanBodies(): void
    {
        if (! class_exists(Scribe::class)) {
            return;
        }

        Scribe::afterGenerating(function (array $paths) {
            $collection = $paths['postman'] ?? null;

            if (! $collection || ! is_file($collection)) {
                return;
            }

            $document = json_decode(file_get_contents($collection), true);

            if (! is_array($document)) {
                return;
            }

            if (! isset($document['item']) || ! is_array($document['item'])) {
                return;
            }

            // Passed by reference, so it has to be a variable rather than a
            // null-coalesced expression.
            $this->tidyPostmanItems($document['item']);

            file_put_contents(
                $collection,
                json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        });
    }

    /**
     * Walk the folders and requests, tidying each request in place.
     *
     * @param  array<int, mixed>  $items
     */
    protected function tidyPostmanItems(array &$items): void
    {
        foreach ($items as &$item) {
            if (isset($item['item']) && is_array($item['item'])) {
                $this->tidyPostmanItems($item['item']);

                continue;
            }

            if (! isset($item['request']) || ! is_array($item['request'])) {
                continue;
            }

            $request = &$item['request'];

            if (($request['body']['mode'] ?? null) === 'raw' && is_string($request['body']['raw'] ?? null)) {
                $decoded = json_decode($request['body']['raw'], true);

                if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                    $request['body']['raw'] = json_encode(
                        $decoded,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    );
                }
            }

            if (isset($request['url']['query']) && is_array($request['url']['query'])) {
                $enabled = array_filter(
                    $request['url']['query'],
                    fn (array $param): bool => ! ($param['disabled'] ?? false),
                );

                $query = implode('&', array_map(
                    fn (array $param): string => rawurlencode($param['key']).'='.rawurlencode((string) ($param['value'] ?? '')),
                    $enabled,
                ));

                $base = explode('?', (string) ($request['url']['raw'] ?? ''), 2)[0];
                $request['url']['raw'] = $query === '' ? $base : $base.'?'.$query;
            }

            unset($request);
        }
    }
}
