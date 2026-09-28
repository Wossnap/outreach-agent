<div>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">
                    Replies
                    @if ($unreadCount > 0)
                        <x-pill tone="good" class="ml-2 font-sans">{{ $unreadCount }} unread</x-pill>
                    @endif
                </h2>
                <div class="flex items-center gap-3 text-sm">
                    <select wire:model.live="classification" class="rounded-md border-rule-strong bg-surface text-ink text-sm focus:border-brand focus:ring-brand">
                        <option value="">All types</option>
                        <option value="reply">Replies</option>
                        <option value="bounce">Bounces</option>
                        <option value="unsubscribe">Unsubscribes</option>
                        <option value="auto_reply">Auto-replies</option>
                    </select>
                    <label class="inline-flex items-center gap-2 text-ink-dim">
                        <input type="checkbox" wire:model.live="unread" value="1" class="rounded border-rule-strong text-brand focus:ring-brand"> Unread only
                    </label>
                    @if ($unreadCount > 0)
                        <button wire:click="markAllRead" class="font-medium text-brand hover:underline">Mark all read</button>
                    @endif
                </div>
            </div>

            @forelse ($replies as $reply)
                <div wire:key="reply-{{ $reply->id }}"
                    class="bg-surface border border-rule sm:rounded-card p-6 space-y-2 {{ $reply->read_at ? 'opacity-75' : 'border-l-4 border-l-brand' }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold text-ink">
                                {{ $reply->contact?->name ?: $reply->from_email }}
                                <span class="font-normal text-ink-dim text-sm">→ {{ $reply->mailbox->email }}</span>
                            </p>
                            <p class="text-sm text-ink-dim">{{ $reply->subject }}</p>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <x-pill :tone="match ($reply->classification) { 'reply' => 'good', 'bounce' => 'danger', 'unsubscribe' => 'warn', 'auto_reply' => 'neutral', default => 'neutral' }">{{ str_replace('_', ' ', $reply->classification) }}</x-pill>
                            @if ($reply->enrollment)
                                <x-pill tone="neutral">{{ $reply->enrollment->automation->tag }}</x-pill>
                            @endif
                            <span class="text-ink-dim">{{ $reply->received_at?->timezone(config('outreach.timezone'))->format('D j M, H:i') }}</span>
                        </div>
                    </div>
                    <details @if(!$reply->read_at) x-on:toggle="$wire.markRead({{ $reply->id }})" @endif>
                        <summary class="cursor-pointer text-sm text-ink-dim hover:text-ink">{{ $reply->snippet }}</summary>
                        <p class="mt-2 whitespace-pre-line text-sm text-ink border-l-2 border-rule pl-4">{{ $reply->body_text }}</p>
                    </details>
                </div>
            @empty
                <div class="bg-surface border border-rule sm:rounded-card p-10 text-center text-ink-dim">
                    No inbound email yet. Replies, bounces and opt-outs will appear here as mailbox polling picks them up.
                </div>
            @endforelse

            {{ $replies->links() }}
        </div>
    </div>
</div>
