@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-md border-rule-strong bg-surface text-ink placeholder:text-ink-dim focus:border-brand focus:ring-brand']) }}>
