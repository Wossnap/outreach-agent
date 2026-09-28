<div>
    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between">
                <h2 class="font-display text-2xl font-semibold tracking-tight text-ink">Activity &amp; failures</h2>
                <select wire:model.live="level" class="rounded-md border-rule-strong bg-surface text-ink focus:border-brand focus:ring-brand text-sm">
                    <option value="">All levels</option>
                    <option value="error">Errors</option>
                    <option value="warning">Warnings</option>
                    <option value="info">Info</option>
                </select>
            </div>

            @if (session('activity-status'))
                <div class="rounded-md bg-band border border-rule p-3 text-sm text-ink">{{ session('activity-status') }}</div>
            @endif
            @if (session('activity-error'))
                <div class="rounded-md bg-band border border-danger p-3 text-sm text-danger">{{ session('activity-error') }}</div>
            @endif

            <div class="bg-surface border border-rule sm:rounded-card divide-y divide-rule">
                @forelse ($logs as $log)
                    <div class="p-4 flex items-start justify-between gap-4" wire:key="log-{{ $log->id }}">
                        <div class="flex items-start gap-3">
                            <x-pill class="mt-0.5 shrink-0" :tone="match ($log->level) {
                                'error' => 'danger',
                                'warning' => 'warn',
                                default => 'neutral',
                            }">{{ $log->level }}</x-pill>
                            <div>
                                <p class="text-sm text-ink">{{ $log->message }}</p>
                                <p class="text-xs text-ink-dim mt-0.5">
                                    {{ $log->event }} · {{ $log->created_at->timezone(config('outreach.timezone'))->format('D j M, H:i:s') }}
                                    @if ($log->retried_at) · retried {{ $log->retried_at->diffForHumans() }} @endif
                                </p>
                            </div>
                        </div>
                        @if ($log->retryable && ! $log->retried_at)
                            <button wire:click="retry({{ $log->id }})"
                                class="shrink-0 text-xs font-medium text-brand hover:underline">
                                Retry
                            </button>
                        @endif
                    </div>
                @empty
                    <div class="p-10 text-center text-ink-dim">Nothing logged yet. Failures (drafting, sending, polling, health pauses) show up here with retry buttons.</div>
                @endforelse
            </div>

            {{ $logs->links() }}
        </div>
    </div>
</div>