@props(['field', 'sortField', 'sortDirection'])

<th {{ $attributes->merge(['class' => 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim']) }}>
    <button wire:click="sortBy('{{ $field }}')" class="inline-flex items-center gap-1 uppercase tracking-label hover:text-ink transition">
        {{ $slot }}
        @if ($sortField === $field)
            <span class="text-ink">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
        @else
            <span class="text-rule-strong" aria-hidden="true">↕</span>
        @endif
    </button>
</th>
