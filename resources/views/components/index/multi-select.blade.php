{{--
    A multi-select in the select2 style: what is picked shows as chips in the
    box, the list opens underneath with a search field, and the list stays
    open while several things are ticked.

    `model` is the Livewire array property the choice is bound to. The ticks
    inside are ordinary checkboxes on that property, so Livewire owns the
    state; Alpine only opens the panel and narrows the list.
--}}
@props([
    'model',
    'options' => [],
    'selected' => [],
    'label' => null,
    'placeholder' => 'Any',
    'empty' => 'Nothing to choose from yet',
])

@php($selected = array_map(strval(...), $selected))

<div x-data="{ open: false, q: '' }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative" data-multi-select="{{ $model }}">
    @if ($label)
        <x-input-label :value="$label" />
    @endif

    <button type="button"
        @click="open = ! open; $nextTick(() => open && $refs.search.focus())"
        class="mt-1 w-full min-h-[38px] flex flex-wrap items-center gap-1 px-2 py-1 text-left text-sm rounded-md shadow-sm border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        @forelse ($selected as $value)
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200 text-xs">
                {{ $options[$value] ?? $value }}
                <span role="button" title="Remove"
                    @click.stop="$wire.set('{{ $model }}', $wire.get('{{ $model }}').filter(v => String(v) !== @js($value)))"
                    class="text-indigo-500 hover:text-indigo-800 dark:hover:text-white">&times;</span>
            </span>
        @empty
            <span class="text-gray-400 dark:text-gray-500">{{ $placeholder }}</span>
        @endforelse
        <span class="ml-auto pl-1 text-gray-400">&#9662;</span>
    </button>

    <div x-show="open" x-cloak
        class="absolute z-20 mt-1 w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg">
        <div class="p-2 border-b border-gray-100 dark:border-gray-700">
            <input x-ref="search" x-model="q" type="text" placeholder="Search…"
                class="w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <ul class="max-h-56 overflow-y-auto py-1">
            @forelse ($options as $value => $optionLabel)
                <li x-show="q === '' || $el.dataset.label.includes(q.toLowerCase())" data-label="{{ mb_strtolower($optionLabel) }}">
                    <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                        <input type="checkbox" wire:model.live="{{ $model }}" value="{{ $value }}" class="rounded border-gray-300 dark:border-gray-600">
                        {{ $optionLabel }}
                    </label>
                </li>
            @empty
                <li class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ $empty }}</li>
            @endforelse
        </ul>
        @if ($selected !== [])
            <div class="p-2 border-t border-gray-100 dark:border-gray-700">
                <button type="button" wire:click="$set('{{ $model }}', [])" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Clear</button>
            </div>
        @endif
    </div>
</div>
