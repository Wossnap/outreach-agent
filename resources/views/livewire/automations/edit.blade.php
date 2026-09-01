<div>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Edit automation</h2>
                <a href="{{ route('automations.index') }}" wire:navigate class="text-sm text-gray-500 hover:underline">&larr; All automations</a>
            </div>

            <form wire:submit="save" class="space-y-6">
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-4">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" wire:model="name" class="mt-1 w-full" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tag" value="Tag (used by the API)" />
                            <x-text-input id="tag" wire:model="tag" class="mt-1 w-full font-mono" />
                            <x-input-error :messages="$errors->get('tag')" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="description" value="Description (optional)" />
                        <textarea id="description" wire:model="description" rows="2"
                            class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"></textarea>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="active" class="rounded border-gray-300 dark:border-gray-700">
                        Active — new contacts with this tag are enrolled
                    </label>
                </div>

                <div class="space-y-4">
                    @foreach ($steps as $index => $step)
                        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-3" wire:key="step-{{ $index }}">
                            <div class="flex items-center justify-between">
                                <h3 class="font-semibold text-gray-800 dark:text-gray-200">Step {{ $index + 1 }}</h3>
                                <div class="flex items-center gap-2 text-sm">
                                    <button type="button" wire:click="moveStep({{ $index }}, -1)" @disabled($index === 0)
                                        class="px-2 py-1 rounded border dark:border-gray-700 disabled:opacity-30">&uarr;</button>
                                    <button type="button" wire:click="moveStep({{ $index }}, 1)" @disabled($index === count($steps) - 1)
                                        class="px-2 py-1 rounded border dark:border-gray-700 disabled:opacity-30">&darr;</button>
                                    <button type="button" wire:click="removeStep({{ $index }})"
                                        class="px-2 py-1 rounded border border-red-300 text-red-600 dark:border-red-800">Remove</button>
                                </div>
                            </div>

                            @if ($index > 0)
                                <div class="flex items-end gap-3">
                                    <div>
                                        <x-input-label value="Wait days" />
                                        <x-text-input type="number" min="0" wire:model="steps.{{ $index }}.delay_days" class="mt-1 w-24" />
                                    </div>
                                    <div>
                                        <x-input-label value="+ hours" />
                                        <x-text-input type="number" min="0" max="23" wire:model="steps.{{ $index }}.delay_hours" class="mt-1 w-24" />
                                    </div>
                                    <p class="text-xs text-gray-500 pb-2">after the previous email was sent (skipped if the contact replied)</p>
                                </div>
                            @endif

                            <div>
                                <x-input-label value="Drafting instructions" />
                                <textarea wire:model="steps.{{ $index }}.drafting_instructions" rows="5"
                                    placeholder="e.g. Introduce yourself as the founder of X. Mention their site by name and one specific reason a link swap makes sense. Keep it under 120 words, casual tone, one clear ask."
                                    class="mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm font-mono text-sm"></textarea>
                                <x-input-error :messages="$errors->get('steps.'.$index.'.drafting_instructions')" class="mt-1" />
                                <p class="mt-1 text-xs text-gray-500">Claude drafts each email from these instructions plus the contact's data (name, company, website, custom fields). Every draft still needs your approval before it is scheduled.</p>
                            </div>

                            <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                                <x-input-label value="Attachments" />

                                @if (! empty($step['attachments']))
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($step['attachments'] as $attachment)
                                            <li class="flex items-center justify-between text-sm bg-gray-50 dark:bg-gray-900 rounded px-3 py-2"
                                                wire:key="attachment-{{ $attachment['id'] }}">
                                                <span class="truncate">
                                                    {{ $attachment['filename'] }}
                                                    <span class="text-xs text-gray-500">({{ number_format($attachment['size'] / 1024, 0) }} KB)</span>
                                                </span>
                                                <button type="button"
                                                    wire:click="removeAttachment({{ $index }}, '{{ $attachment['id'] }}')"
                                                    wire:confirm="Remove this attachment? Emails from this step will go out without it."
                                                    class="text-xs text-red-600 hover:underline shrink-0 ml-3">Remove</button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($step['id'])
                                    <div class="mt-2 flex items-center gap-3">
                                        <input type="file" wire:model="newAttachment.{{ $index }}"
                                            class="text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-gray-100 dark:file:bg-gray-700 dark:file:text-gray-200">
                                        <button type="button" wire:click="uploadAttachment({{ $index }})"
                                            class="text-xs px-3 py-1 rounded bg-gray-800 text-white hover:bg-gray-700">Attach</button>
                                    </div>
                                    <div wire:loading wire:target="newAttachment.{{ $index }}" class="mt-1 text-xs text-gray-500">Uploading...</div>
                                @else
                                    <p class="mt-2 text-xs text-gray-500">Save the automation first, then you can attach a file to this step.</p>
                                @endif

                                <x-input-error :messages="$errors->get('newAttachment.'.$index)" class="mt-1" />

                                <p class="mt-1 text-xs text-gray-500">
                                    Attached to this step's email only. To send the same file with every email in the sequence, add it to each step.
                                    Max {{ number_format(config('outreach.attachments.max_size_kb') / 1024, 0) }} MB, {{ config('outreach.attachments.max_per_step') }} per step.
                                </p>
                            </div>
                        </div>
                    @endforeach

                    <button type="button" wire:click="addStep"
                        class="w-full border-2 border-dashed border-gray-300 dark:border-gray-700 rounded-lg py-3 text-sm text-gray-500 hover:border-gray-400">
                        + Add follow-up step
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>Save automation</x-primary-button>
                    <x-action-message on="saved">Saved.</x-action-message>
                </div>
            </form>
        </div>
    </div>
</div>
