<div>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                    Replies
                    @if ($unreadCount > 0)
                        <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-indigo-600 text-white">{{ $unreadCount }} unread</span>
                    @endif
                </h2>
                <div class="flex items-center gap-3 text-sm">
                    <select wire:model.live="classification" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md text-sm">
                        <option value="">All types</option>
                        <option value="reply">Replies</option>
                        <option value="bounce">Bounces</option>
                        <option value="unsubscribe">Unsubscribes</option>
                        <option value="auto_reply">Auto-replies</option>
                    </select>
                    <label class="inline-flex items-center gap-2 text-gray-600 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="unread" value="1" class="rounded border-gray-300 dark:border-gray-600"> Unread only
                    </label>
                    @if ($unreadCount > 0)
                        <button wire:click="markAllRead" class="text-indigo-600 hover:underline">Mark all read</button>
                    @endif
                </div>
            </div>

            @forelse ($replies as $reply)
                <div wire:key="reply-{{ $reply->id }}"
                    class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-2 {{ $reply->read_at ? 'opacity-75' : 'border-l-4 border-indigo-500' }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100">
                                {{ $reply->contact?->name ?: $reply->from_email }}
                                <span class="font-normal text-gray-500 dark:text-gray-300 text-sm">→ {{ $reply->mailbox->email }}</span>
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $reply->subject }}</p>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span @class([
                                'px-2 py-1 rounded font-semibold',
                                'bg-green-100 text-green-800' => $reply->classification === 'reply',
                                'bg-red-100 text-red-800' => $reply->classification === 'bounce',
                                'bg-yellow-100 text-yellow-800' => $reply->classification === 'unsubscribe',
                                'bg-gray-100 text-gray-600' => $reply->classification === 'auto_reply',
                            ])>{{ str_replace('_', ' ', $reply->classification) }}</span>
                            @if ($reply->enrollment)
                                <span class="px-2 py-1 rounded bg-indigo-100 text-indigo-800 font-mono">{{ $reply->enrollment->automation->tag }}</span>
                            @endif
                            <span class="text-gray-500 dark:text-gray-400">{{ $reply->received_at?->timezone(config('outreach.timezone'))->format('D j M, H:i') }}</span>
                        </div>
                    </div>
                    <details @if(!$reply->read_at) x-on:toggle="$wire.markRead({{ $reply->id }})" @endif>
                        <summary class="cursor-pointer text-sm text-gray-500 dark:text-gray-300 hover:text-gray-700">{{ $reply->snippet }}</summary>
                        <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300 border-l-2 border-gray-200 dark:border-gray-700 pl-4">{{ $reply->body_text }}</p>
                    </details>
                </div>
            @empty
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-10 text-center text-gray-500 dark:text-gray-300">
                    No inbound email yet. Replies, bounces and opt-outs will appear here as mailbox polling picks them up.
                </div>
            @endforelse

            {{ $replies->links() }}
        </div>
    </div>
</div>
