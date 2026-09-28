<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Automations</h2>
            </div>

            <div class="bg-surface border border-rule sm:rounded-card p-6">
                <form wire:submit="create" class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="newName" value="Name" />
                        <x-text-input id="newName" wire:model.live.debounce.300ms="newName" placeholder="SEO Backlinks" class="mt-1" />
                        <x-input-error :messages="$errors->get('newName')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="newTag" value="Tag (used by the API)" />
                        <x-text-input id="newTag" wire:model="newTag" placeholder="seo-backlinks" class="mt-1" />
                        <x-input-error :messages="$errors->get('newTag')" class="mt-1" />
                    </div>
                    <x-primary-button>New automation</x-primary-button>
                </form>
            </div>

            <div class="bg-surface border border-rule sm:rounded-card overflow-x-auto">
                <table class="min-w-full divide-y divide-rule text-sm">
                    <thead class="text-left">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Tag</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Steps</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Active enrollments</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-label text-ink-dim">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule text-ink">
                        @forelse ($automations as $automation)
                            <tr>
                                <td class="px-6 py-4 font-medium">{{ $automation->name }}</td>
                                <td class="px-6 py-4 text-xs">{{ $automation->tag }}</td>
                                <td class="px-6 py-4">{{ $automation->steps_count }}</td>
                                <td class="px-6 py-4">{{ $automation->active_enrollments_count }}</td>
                                <td class="px-6 py-4">
                                    <button wire:click="toggleActive({{ $automation->id }})"
                                        class="rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-page">
                                        <x-pill :tone="$automation->active ? 'good' : 'neutral'">{{ $automation->active ? 'Active' : 'Inactive' }}</x-pill>
                                    </button>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('automations.edit', $automation) }}" wire:navigate
                                        class="font-medium text-brand hover:underline">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-ink-dim">
                                    No automations yet. Create one above — its tag is what your other apps send to <code class="font-mono text-sm">POST /api/contacts</code>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>