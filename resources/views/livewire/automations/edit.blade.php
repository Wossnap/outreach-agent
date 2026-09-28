<div>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Edit automation</h2>
                <a href="{{ route('automations.index') }}" wire:navigate class="text-sm font-medium text-brand hover:underline">&larr; All automations</a>
            </div>

            <form wire:submit="save" class="space-y-6">
                <div class="bg-surface border border-rule sm:rounded-card p-6 space-y-4">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" wire:model="name" class="mt-1 w-full" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tag" value="Tag (used by the API)" />
                            <x-text-input id="tag" wire:model="tag" class="mt-1 w-full" />
                            <x-input-error :messages="$errors->get('tag')" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="description" value="Description (optional)" />
                        <textarea id="description" wire:model="description" rows="2"
                            class="mt-1 w-full rounded-md border-rule-strong bg-surface text-ink focus:border-brand focus:ring-brand"></textarea>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="active" class="rounded border-rule-strong text-brand focus:ring-brand">
                        Active — new contacts with this tag are enrolled
                    </label>
                </div>

                <div class="space-y-4">
                    @foreach ($steps as $index => $step)
                        <div class="bg-surface border border-rule sm:rounded-card p-6 space-y-3" wire:key="step-{{ $index }}">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-semibold text-ink">Step {{ $index + 1 }}</h3>
                                <div class="flex items-center gap-2 text-sm">
                                    <button type="button" wire:click="moveStep({{ $index }}, -1)" @disabled($index === 0)
                                        class="px-2 py-1 rounded-md border border-rule-strong text-ink hover:border-ink-dim disabled:opacity-30 transition">&uarr;</button>
                                    <button type="button" wire:click="moveStep({{ $index }}, 1)" @disabled($index === count($steps) - 1)
                                        class="px-2 py-1 rounded-md border border-rule-strong text-ink hover:border-ink-dim disabled:opacity-30 transition">&darr;</button>
                                    <button type="button" wire:click="removeStep({{ $index }})"
                                        class="px-2 py-1 rounded-md border border-danger text-danger hover:bg-danger/10 transition">Remove</button>
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
                                    <p class="text-xs text-ink-dim pb-2">after the previous email was sent (skipped if the contact replied)</p>
                                </div>
                            @endif

                            <div>
                                <x-input-label value="Drafting instructions" />
                                <textarea wire:model="steps.{{ $index }}.drafting_instructions" rows="5"
                                    placeholder="e.g. Introduce yourself as the founder of X. Mention their site by name and one specific reason a link swap makes sense. Keep it under 120 words, casual tone, one clear ask."
                                    class="mt-1 w-full rounded-md border-rule-strong bg-surface text-ink focus:border-brand focus:ring-brand text-sm"></textarea>
                                <x-input-error :messages="$errors->get('steps.'.$index.'.drafting_instructions')" class="mt-1" />
                                <p class="mt-1 text-xs text-ink-dim">Claude drafts each email from these instructions plus the lead's data (name, job title, company, domain, and anything the source sent). Every draft still needs your approval before it is scheduled.</p>
                            </div>

                            <div class="border-t border-rule pt-3">
                                <x-input-label value="Attachments" />

                                @if (! empty($step['attachments']))
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($step['attachments'] as $attachment)
                                            <li class="flex items-center justify-between text-sm bg-band rounded-md px-3 py-2"
                                                wire:key="attachment-{{ $attachment['id'] }}">
                                                <span class="truncate">
                                                    {{ $attachment['filename'] }}
                                                    <span class="text-xs text-ink-dim">({{ number_format($attachment['size'] / 1024, 0) }} KB)</span>
                                                </span>
                                                <button type="button"
                                                    wire:click="removeAttachment({{ $index }}, '{{ $attachment['id'] }}')"
                                                    wire:confirm="Remove this attachment? Emails from this step will go out without it."
                                                    class="text-xs font-medium text-danger hover:underline shrink-0 ml-3">Remove</button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <div class="mt-2 flex items-center gap-3">
                                    <input type="file" wire:model="newAttachment.{{ $index }}"
                                        class="text-sm text-ink-dim file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-band file:text-ink">
                                    @if ($step['id'])
                                        <button type="button" wire:click="uploadAttachment({{ $index }})"
                                            class="text-xs px-3 py-1.5 rounded-md bg-brand text-brand-ink font-semibold hover:bg-brand-hover transition">Attach now</button>
                                    @endif
                                </div>
                                <div wire:loading wire:target="newAttachment.{{ $index }}" class="mt-1 text-xs text-ink-dim">Uploading...</div>
                                {{-- A chosen file is attached by "Save automation" too, so choosing
                                     one and saving no longer loses it. --}}
                                @if (! empty($newAttachment[$index]))
                                    <p class="mt-1 text-xs text-warn">
                                        {{ $newAttachment[$index]->getClientOriginalName() }} is chosen but not attached yet. Press "Attach now" or "Save automation".
                                    </p>
                                @endif

                                <x-input-error :messages="$errors->get('newAttachment.'.$index)" class="mt-1" />

                                <p class="mt-1 text-xs text-ink-dim">
                                    Attached to this step's email only. To send the same file with every email in the sequence, add it to each step.
                                    Max {{ number_format(config('outreach.attachments.max_size_kb') / 1024, 0) }} MB, {{ config('outreach.attachments.max_per_step') }} per step.
                                </p>
                            </div>
                        </div>
                    @endforeach

                    <button type="button" wire:click="addStep"
                        class="w-full border-2 border-dashed border-rule-strong rounded-card py-3 text-sm text-ink-dim hover:border-ink-dim hover:text-ink transition">
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