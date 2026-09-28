<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center gap-2 px-4 py-2 rounded-md border border-rule-strong bg-transparent text-ink text-sm font-semibold hover:border-ink-dim active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-page disabled:opacity-50 transition']) }}>
    {{ $slot }}
</button>
