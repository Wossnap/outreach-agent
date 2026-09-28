{{--
    A status pill. `tone` is the meaning, not the colour, so a test can ask
    for data-tone="danger" and the palette can change underneath it.

    good     solid navy: ok, valid, healthy, sent, active
    warn     gold outline: risky, pending, paused
    danger   brick outline: invalid, bounced, failed, critical
    neutral  sand fill: unknown, not checked, plain counts
--}}
@props(['tone' => 'neutral'])

<span data-tone="{{ $tone }}" {{ $attributes->class([
    'inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold uppercase tracking-label whitespace-nowrap',
    'bg-band text-ink border-rule' => $tone === 'neutral',
    'bg-brand text-brand-ink border-brand' => $tone === 'good',
    'text-warn border-warn' => $tone === 'warn',
    'text-danger border-danger' => $tone === 'danger',
]) }}>{{ $slot }}</span>
