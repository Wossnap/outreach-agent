<div>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Activity &amp; failures</h2>
                <select wire:model.live="level" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md text-sm">
                    <option value="">All levels</option>
                    <option value="error">Errors</option>
                    <option value="warning">Warnings</option>
                    <option value="info">Info</option>
                </select>
            </div>

            @if (session('activity-status'))
                <div class="rounded-md bg-green-50 dark:bg-green-900/30 p-3 text-sm text-green-800 dark:text-green-200">{{ session('activity-status') }}</div>
            @endif
            @if (session('activity-error'))
                <div class="rounded-md bg-red-50 dark:bg-red-900/30 p-3 text-sm text-red-800 dark:text-red-200">{{ session('activity-error') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($logs as $log)
                    <div class="p-4 flex items-start justify-between gap-4" wire:key="log-{{ $log->id }}">
                        <div class="flex items-start gap-3">
                            <span @class([
                                'mt-0.5 px-2 py-0.5 rounded text-xs font-semibold shrink-0',
                                'bg-red-100 text-red-800' => $log->level === 'error',
                                'bg-yellow-100 text-yellow-800' => $log->level === 'warning',
                                'bg-gray-100 text-gray-600' => $log->level === 'info',
                            ])>{{ $log->level }}</span>
                            <div>
                                <p class="text-sm text-gray-900 dark:text-gray-100">{{ $log->message }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ $log->event }} · {{ $log->created_at->timezone(config('outreach.timezone'))->format('D j M, H:i:s') }}
                                    @if ($log->retried_at) · retried {{ $log->retried_at->diffForHumans() }} @endif
                                </p>
                            </div>
                        </div>
                        @if ($log->retryable && ! $log->retried_at)
                            <button wire:click="retry({{ $log->id }})"
                                class="shrink-0 px-3 py-1.5 text-xs rounded-md border border-indigo-300 text-indigo-700 dark:border-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/20">
                                Retry
                            </button>
                        @endif
                    </div>
                @empty
                    <div class="p-10 text-center text-gray-500 dark:text-gray-300">Nothing logged yet. Failures (drafting, sending, polling, health pauses) show up here with retry buttons.</div>
                @endforelse
            </div>

            {{ $logs->links() }}
        </div>
    </div>
</div>
