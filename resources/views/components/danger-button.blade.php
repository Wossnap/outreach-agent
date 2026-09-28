<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-2 px-4 py-2 rounded-md border border-danger bg-transparent text-danger text-sm font-semibold hover:bg-danger/10 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2 focus-visible:ring-offset-page disabled:opacity-50 transition']) }}>
    {{ $slot }}
</button>
