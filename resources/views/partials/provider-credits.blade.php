{{--
    What one provider has left, as of the last time anybody asked.

    Reads only what is stored. Nothing here calls a provider, so this partial
    can appear six times on a page without the page waiting on anybody.

    Four different things a blank could mean, and only one of them is a thing
    to go and fix, so each is said in words:
      - no balance endpoint written for this driver
      - no key, which the badge above already says loudly, so nothing here
      - nobody has pressed the button yet
      - the provider was asked and could not be reached
--}}
@php($reading = $provider->cachedBalance())

@if (! \App\Models\EnrichmentProvider::reportsBalanceForDriver($provider->driver))
    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
        No balance endpoint. This provider does not publish what is left.
    </p>
@elseif (! $provider->isConfigured())
    {{-- The "no key" badge beside the name has already said this. --}}
@elseif ($reading === null)
    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
        Credits not checked yet.
    </p>
@elseif (! $reading->succeeded())
    <p class="text-xs text-red-600 dark:text-red-400 mt-1">
        Could not check credits {{ $reading->checkedAt->diffForHumans() }}: {{ $reading->error }}
    </p>
@else
    @php($low = $reading->balance->isEmpty() ? 'empty' : ($reading->balance->remaining < 25 ? 'low' : 'ok'))
    <p class="text-xs mt-1">
        <span @class([
            'font-medium',
            'text-red-600 dark:text-red-400' => $low === 'empty',
            'text-amber-600 dark:text-amber-400' => $low === 'low',
            'text-gray-700 dark:text-gray-300' => $low === 'ok',
        ])>{{ number_format($reading->balance->remaining) }} {{ $reading->balance->describe() }}</span>
        {{-- A figure with no date on it invites reading a week-old balance as
             the balance now. Said on every row, not only the stale ones. --}}
        <span class="text-gray-500 dark:text-gray-400">
            · as of {{ $reading->checkedAt->diffForHumans() }}
            ({{ $reading->checkedAt->timezone(config('outreach.timezone'))->format('j M Y, H:i') }})
        </span>
        @if ($low === 'empty')
            <span class="block text-red-600 dark:text-red-400">Nothing left. Every call to this provider fails until it is topped up.</span>
        @endif
    </p>
@endif
