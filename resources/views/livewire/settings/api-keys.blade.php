<div>
    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">API keys</h2>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Your other apps authenticate to the API with these keys. Full reference at
                    <a href="{{ config('app.url') }}/docs" class="underline">{{ config('app.url') }}/docs</a>.
                    <code class="font-mono text-xs bg-gray-100 dark:bg-gray-900 px-2 py-1 rounded block mt-2">curl -X POST {{ config('app.url') }}/api/contacts -H "Authorization: Bearer &lt;key&gt;" -H "Content-Type: application/json" -d '{"email":"jane@example.com","first_name":"Jane","last_name":"Doe","tags":["seo-backlinks"]}'</code>
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
                                        class="mt-1 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                                    <span>
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $ability }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-300">{{ $description }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('newKeyAbilities')" class="mt-1" />
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-300">
                            Give a key only what it needs. A tool that just pushes leads needs
                            <span class="font-mono">write</span>, not <span class="font-mono">read</span>.
                        </p>
                    </div>

                    <x-primary-button>Create key</x-primary-button>
                </form>

                @if ($plainTextKey)
                    <div class="rounded-md bg-yellow-50 dark:bg-yellow-900/30 p-4 text-sm">
                        <p class="font-semibold text-yellow-800 dark:text-yellow-200">Copy this key now, it is shown only once:</p>
                        <code class="font-mono text-xs block mt-2 break-all select-all">{{ $plainTextKey }}</code>
                    </div>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($tokens as $token)
                    <div class="p-4 flex items-center justify-between" wire:key="token-{{ $token->id }}">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $token->name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-300">
                                {{ implode(', ', $token->abilities ?? []) }}
                                · created {{ $token->created_at->diffForHumans() }}
                                · last used {{ $token->last_used_at?->diffForHumans() ?? 'never' }}
                            </p>
                        </div>
                        <button wire:click="revoke({{ $token->id }})"
                            wire:confirm="Revoke this key? Apps using it will get 401s immediately."
                            class="text-xs text-red-600 hover:underline">Revoke</button>
                    </div>
                @empty
                    <div class="p-8 text-center text-gray-500 dark:text-gray-300 text-sm">No API keys yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
