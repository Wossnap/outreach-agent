<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="bg-surface border-b border-rule">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" wire:navigate>
                        <x-wordmark class="text-xl" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('approvals')" :active="request()->routeIs('approvals')" wire:navigate>
                        {{ __('Approvals') }}
                        @php($pendingCount = \App\Models\Message::query()->where('status', \App\Models\Message::STATUS_PENDING_APPROVAL)->count())
                        @if ($pendingCount > 0)
                            <x-pill tone="good" class="ms-1">{{ $pendingCount }}</x-pill>
                        @endif
                    </x-nav-link>
                    <x-nav-link :href="route('replies.inbox')" :active="request()->routeIs('replies.*')" wire:navigate>
                        {{ __('Replies') }}
                        @php($unreadReplies = \App\Models\Reply::query()->whereNull('read_at')->count())
                        @if ($unreadReplies > 0)
                            <x-pill tone="good" class="ms-1">{{ $unreadReplies }}</x-pill>
                        @endif
                    </x-nav-link>
                    <x-nav-link :href="route('contacts.index')" :active="request()->routeIs('contacts.*')" wire:navigate>
                        {{ __('Leads') }}
                    </x-nav-link>
                    {{-- Eleven links did not fit across the bar, and the four
                         above are the ones opened daily. The rest are grouped
                         by why you would open them: to change how the system
                         works, or to see how it is doing. --}}
                    {{-- Inline @php(), like the two counts above. A @php block
                         here compiles into the surrounding component tags and
                         breaks the view. --}}
                    @php($setupActive = request()->routeIs('automations.*') || request()->routeIs('mailboxes.*') || request()->routeIs('settings.waterfall') || request()->routeIs('settings.api-keys'))
                    @php($reportsActive = request()->routeIs('health') || request()->routeIs('activity.*') || request()->routeIs('settings.waterfall-performance'))

                    <div class="inline-flex items-center">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <button class="inline-flex items-center h-16 px-1 pt-1 border-b-2 text-sm font-medium leading-5 focus:outline-none transition {{ $setupActive ? 'border-brand text-ink' : 'border-transparent text-ink-dim hover:text-ink hover:border-rule-strong' }}">
                                    {{ __('Setup') }}
                                    <svg class="ms-1 fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('automations.index')" wire:navigate>{{ __('Automations') }}</x-dropdown-link>
                                <x-dropdown-link :href="route('mailboxes.index')" wire:navigate>{{ __('Mailboxes') }}</x-dropdown-link>
                                <x-dropdown-link :href="route('settings.waterfall')" wire:navigate>{{ __('Email waterfall') }}</x-dropdown-link>
                                <x-dropdown-link :href="route('settings.api-keys')" wire:navigate>{{ __('API keys') }}</x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>

                    <div class="inline-flex items-center">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <button class="inline-flex items-center h-16 px-1 pt-1 border-b-2 text-sm font-medium leading-5 focus:outline-none transition {{ $reportsActive ? 'border-brand text-ink' : 'border-transparent text-ink-dim hover:text-ink hover:border-rule-strong' }}">
                                    {{ __('Reports') }}
                                    <svg class="ms-1 fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('settings.waterfall-performance')" wire:navigate>{{ __('Spend') }}</x-dropdown-link>
                                <x-dropdown-link :href="route('settings.lookups')" wire:navigate>{{ __('Lookups') }}</x-dropdown-link>
                                <x-dropdown-link :href="route('health')" wire:navigate>{{ __('Health') }}</x-dropdown-link>
                                <x-dropdown-link :href="route('activity.index')" wire:navigate>{{ __('Activity') }}</x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-ink-dim bg-surface hover:text-ink focus:outline-none transition">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        {{-- Theme, kept in the browser rather than on the
                             account: it is a property of the screen you are
                             sitting at, not of who you are. --}}
                        <div x-data="{
                                theme: 'auto',
                                init() {
                                    try { this.theme = localStorage.getItem('theme') || 'auto'; } catch (e) {}
                                },
                                choose(value) {
                                    this.theme = value;
                                    try {
                                        value === 'auto'
                                            ? localStorage.removeItem('theme')
                                            : localStorage.setItem('theme', value);
                                    } catch (e) {}
                                    window.applyTheme();
                                },
                             }"
                             class="px-4 py-2 border-t border-rule">
                            <div class="text-xs font-semibold uppercase tracking-label text-ink-dim">
                                {{ __('Theme') }}
                            </div>
                            <div class="mt-2 flex rounded-md border border-rule overflow-hidden">
                                @foreach (['light' => __('Light'), 'dark' => __('Dark'), 'auto' => __('Auto')] as $value => $label)
                                    <button type="button"
                                        @click.stop="choose('{{ $value }}')"
                                        :class="theme === '{{ $value }}'
                                            ? 'bg-brand text-brand-ink'
                                            : 'text-ink-dim hover:bg-band'"
                                        class="flex-1 px-2 py-1 text-xs font-medium transition">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-ink-dim hover:text-ink hover:bg-band focus:outline-none focus:bg-band transition">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            {{-- Every page, because a phone has no dropdowns to fall back on.
                 The two headings match the grouping on the wide bar. --}}
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('approvals')" :active="request()->routeIs('approvals')" wire:navigate>
                {{ __('Approvals') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('replies.inbox')" :active="request()->routeIs('replies.*')" wire:navigate>
                {{ __('Replies') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('contacts.index')" :active="request()->routeIs('contacts.*')" wire:navigate>
                {{ __('Leads') }}
            </x-responsive-nav-link>

            <div class="px-4 pt-4 pb-1 text-xs font-semibold uppercase tracking-label text-ink-dim">
                {{ __('Setup') }}
            </div>
            <x-responsive-nav-link :href="route('automations.index')" :active="request()->routeIs('automations.*')" wire:navigate>
                {{ __('Automations') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('mailboxes.index')" :active="request()->routeIs('mailboxes.*')" wire:navigate>
                {{ __('Mailboxes') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('settings.waterfall')" :active="request()->routeIs('settings.waterfall')" wire:navigate>
                {{ __('Email waterfall') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('settings.api-keys')" :active="request()->routeIs('settings.api-keys')" wire:navigate>
                {{ __('API keys') }}
            </x-responsive-nav-link>

            <div class="px-4 pt-4 pb-1 text-xs font-semibold uppercase tracking-label text-ink-dim">
                {{ __('Reports') }}
            </div>
            <x-responsive-nav-link :href="route('settings.waterfall-performance')" :active="request()->routeIs('settings.waterfall-performance')" wire:navigate>
                {{ __('Spend') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('settings.lookups')" :active="request()->routeIs('settings.lookups')" wire:navigate>
                {{ __('Lookups') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('health')" :active="request()->routeIs('health')" wire:navigate>
                {{ __('Health') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('activity.index')" :active="request()->routeIs('activity.*')" wire:navigate>
                {{ __('Activity') }}
            </x-responsive-nav-link>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-rule">
            <div class="px-4">
                <div class="font-medium text-base text-ink" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-ink-dim">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
