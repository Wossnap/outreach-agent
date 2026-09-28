<nav class="flex justify-center gap-3">
    @auth
        <a
            href="{{ url('/dashboard') }}"
            class="inline-flex items-center px-4 py-2 rounded-md bg-brand text-brand-ink text-sm font-semibold hover:bg-brand-hover transition"
        >
            Dashboard
        </a>
    @else
        <a
            href="{{ route('login') }}"
            class="inline-flex items-center px-4 py-2 rounded-md bg-brand text-brand-ink text-sm font-semibold hover:bg-brand-hover transition"
        >
            Log in
        </a>

        @if (Route::has('register') && config('outreach.registration_enabled'))
            <a
                href="{{ route('register') }}"
                class="inline-flex items-center px-4 py-2 rounded-md border border-rule-strong text-ink text-sm font-semibold hover:border-ink-dim transition"
            >
                Register
            </a>
        @endif
    @endauth
</nav>
