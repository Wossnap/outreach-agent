{{-- The design system's Banner (design-system/src/Banner.tsx): a flash or
     callout on the sand band. info for notes, warn (gold border) for something
     to watch, danger (brick) for a failure. Plain sentences, no icons. --}}
@props(['tone' => 'info'])

@php($tones = [
    'info' => 'border-rule text-ink',
    'warn' => 'border-warn text-ink',
    'danger' => 'border-danger text-danger',
])

<div {{ $attributes->class(['rounded-md bg-band border p-3 text-sm', $tones[$tone] ?? $tones['info']]) }}>
    {{ $slot }}
</div>
