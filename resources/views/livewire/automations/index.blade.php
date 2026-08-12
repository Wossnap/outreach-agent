<div>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Automations</h2>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <form wire:submit="create" class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="newName" value="Name" />
                        <x-text-input id="newName" wire:model.live.debounce.300ms="newName" placeholder="SEO Backlinks" class="mt-1" />
                        <x-input-error :messages="$errors->get('newName')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="newTag" value="Tag (used by the API)" />
                        <x-text-input id="newTag" wire:model="newTag" placeholder="seo-backlinks" class="mt-1 font-mono" />
                        <x-input-error :messages="$errors->get('newTag')" class="mt-1" />
                    </div>
                    <x-primary-button>New automation</x-primary-button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">Name</th>
                            <th class="px-6 py-3 font-medium">Tag</th>
                            <th class="px-6 py-3 font-medium">Steps</th>
                            <th class="px-6 py-3 font-medium">Active enrollments</th>
                            <th class="px-6 py-3 font-medium">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-gray-100">
                        @forelse ($automations as $automation)
                            <tr>
                                <td class="px-6 py-4 font-medium">{{ $automation->name }}</td>
                                <td class="px-6 py-4 font-mono text-xs">{{ $automation->tag }}</td>
                                <td class="px-6 py-4">{{ $automation->steps_count }}</td>
                                <td class="px-6 py-4">{{ $automation->active_enrollments_count }}</td>
                                <td class="px-6 py-4">
                                    <button wire:click="toggleActive({{ $automation->id }})"
                                        class="px-2 py-1 rounded text-xs font-semibold {{ $automation->active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">
                                        {{ $automation->active ? 'Active' : 'Inactive' }}
                                    </button>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('automations.edit', $automation) }}" wire:navigate
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-500">
                                    No automations yet. Create one above — its tag is what your other apps send to <code class="font-mono">POST /api/contacts</code>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
