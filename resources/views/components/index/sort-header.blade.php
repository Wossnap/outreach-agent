@props(['field', 'sortField', 'sortDirection'])

<th {{ $attributes->merge(['class' => 'px-6 py-3 font-medium']) }}>
    <button wire:click="sortBy('{{ $field }}')" class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
        {{ $slot }}
        @if ($sortField === $field)
            <span class="text-indigo-500">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
        @else
            <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">↕</span>
        @endif
    </button>
</th>
