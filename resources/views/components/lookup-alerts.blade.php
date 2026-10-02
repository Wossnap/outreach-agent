{{-- Finders and checkers that cannot work, shown where leads are looked at.
     Until this existed it was only visible on the Email waterfall page, and
     leads sat at Pending for a week before anybody noticed. Reads stored
     balances only, so drawing it never calls a provider. --}}
@php($alerts = app(\App\Services\Enrichment\ProviderHealth::class)->alerts())

@if ($alerts !== [])
    <x-banner tone="warn" data-lookup-alerts>
        {{ implode(' ', $alerts) }}
        <a href="{{ route('settings.waterfall') }}" class="font-medium text-brand hover:underline" wire:navigate>Open Email waterfall</a>
    </x-banner>
@endif
