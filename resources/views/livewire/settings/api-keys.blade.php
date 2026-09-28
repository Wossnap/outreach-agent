<div>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">API keys</h2>

            <div class="bg-surface border border-rule sm:rounded-card p-6 space-y-4">
                <p class="text-sm text-ink-dim">
                    Your other apps authenticate to the API with these keys. Full reference at
                    <a href="{{ config('app.url') }}/docs" class="text-ink underline decoration-rule-strong underline-offset-2 hover:decoration-ink">{{ config('app.url') }}/docs</a>.
                    <code class="block mt-2 bg-navy-deep text-navy-ink rounded-md p-3 font-mono text-xs">curl -X POST {{ config('app.url') }}/api/contacts -H "Authorization: Bearer &lt;key&gt;" -H "Content-Type: application/json" -d '{"email":"jane@example.com","first_name":"Jane","last_name":"Doe","tags":["seo-backlinks"]}'</code>
                </p>

                <form wire:submit="create" class="space-y-4">
                    <div>
                        <x-input-label for="newKeyName" value="Key name (which app is this for?)" />
                        <x-text-input id="newKeyName" wire:model="newKeyName" class="mt-1 w-full" placeholder="tube-trend-tool" />
                        <x-input-error :messages="$errors->get('newKeyName')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label value="What may this key do?" />
                        <div class="mt-2 space-y-2">
                            @foreach ($abilities as $ability => $description)
                                <label class="flex items-start gap-3 text-sm" wire:key="ability-{{ $ability }}">
                                    <input type="checkbox" value="{{ $ability }}" wire:model="newKeyAbilities"
                                        class="mt-1 rounded border-rule-strong text-brand focus:ring-brand">
                                    <span>
                                        <span class="font-medium text-ink">{{ $ability }}</span>
                                        <span class="block text-xs text-ink-dim">{{ $description }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('newKeyAbilities')" class="mt-1" />
                        <p class="mt-2 text-xs text-ink-dim">
                            Give a key only what it needs. A tool that just pushes leads needs
                            <span class="font-mono">write</span>, not <span class="font-mono">read</span>.
                        </p>
                    </div>

                    <x-primary-button>Create key</x-primary-button>
                </form>

                @if ($plainTextKey)
                    <div class="rounded-md bg-band border border-warn p-4 text-sm">
                        <p class="font-semibold text-ink">Copy this key now, it is shown only once:</p>
                        <code class="block mt-2 bg-navy-deep text-navy-ink rounded-md p-3 font-mono text-xs break-all select-all">{{ $plainTextKey }}</code>
                    </div>
                @endif
            </div>

            <div class="bg-surface border border-rule sm:rounded-card divide-y divide-rule">
                @forelse ($tokens as $token)
                    <div class="p-4 flex items-center justify-between" wire:key="token-{{ $token->id }}">
                        <div>
                            <p class="text-sm font-medium text-ink">{{ $token->name }}</p>
                            <p class="text-xs text-ink-dim">
                                {{ implode(', ', $token->abilities ?? []) }}
                                · created {{ $token->created_at->diffForHumans() }}
                                · last used {{ $token->last_used_at?->diffForHumans() ?? 'never' }}
                            </p>
                        </div>
                        <button wire:click="revoke({{ $token->id }})"
                            wire:confirm="Revoke this key? Apps using it will get 401s immediately."
                            class="px-3 py-1.5 rounded-md border border-danger text-danger text-sm font-semibold hover:bg-danger/10 transition">Revoke</button>
                    </div>
                @empty
                    <div class="p-8 text-center text-ink-dim text-sm">No API keys yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>