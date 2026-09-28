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
        class="mt-1 w-full min-h-[38px] flex flex-wrap items-center gap-1 px-2 py-1 text-left text-sm rounded-md border border-rule-strong bg-surface text-ink focus:outline-none focus:ring-2 focus:ring-brand transition">
        @forelse ($selected as $value)
            <x-pill class="normal-case tracking-normal text-xs">
                {{ $options[$value] ?? $value }}
                <span role="button" title="Remove"
                    @click.stop="$wire.set('{{ $model }}', $wire.get('{{ $model }}').filter(v => String(v) !== @js($value)))"
                    class="text-ink-dim hover:text-ink">&times;</span>
            </x-pill>
        @empty
            <span class="text-ink-dim">{{ $placeholder }}</span>
        @endforelse
        <span class="ml-auto pl-1 text-ink-dim">&#9662;</span>
    </button>

    <div x-show="open" x-cloak
        class="absolute z-20 mt-1 w-full rounded-md border border-rule bg-surface">
        <div class="p-2 border-b border-rule">
            <input x-ref="search" x-model="q" type="text" placeholder="Search…"
                class="w-full text-sm rounded-md border-rule-strong bg-surface text-ink placeholder:text-ink-dim focus:border-brand focus:ring-brand">
        </div>
        <ul class="max-h-56 overflow-y-auto py-1">
            @forelse ($options as $value => $optionLabel)
                <li x-show="q === '' || $el.dataset.label.includes(q.toLowerCase())" data-label="{{ mb_strtolower($optionLabel) }}">
                    <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-ink hover:bg-band cursor-pointer">
                        <input type="checkbox" wire:model.live="{{ $model }}" value="{{ $value }}" class="rounded border-rule-strong text-brand focus:ring-brand">
                        {{ $optionLabel }}
                    </label>
                </li>
            @empty
                <li class="px-3 py-2 text-xs text-ink-dim">{{ $empty }}</li>
            @endforelse
        </ul>
        @if ($selected !== [])
            <div class="p-2 border-t border-rule">
                <button type="button" wire:click="$set('{{ $model }}', [])" class="text-xs font-medium text-brand hover:underline">Clear</button>
            </div>
        @endif
    </div>
</div>
