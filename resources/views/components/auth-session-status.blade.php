@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-ink']) }}>
        {{ $status }}
    </div>
@endif
