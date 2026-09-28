{{-- The wordmark is the logo: lowercase serif with the trailing period in
     gold. That period is the one gold mark on every page. --}}
<span {{ $attributes->merge(['class' => 'font-display font-semibold tracking-tight text-ink']) }}>{{ Str::lower(config('app.name')) }}<span class="text-accent">.</span></span>
