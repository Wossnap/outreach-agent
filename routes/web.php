<?php

use App\Http\Controllers\GoogleOAuthController;
use App\Livewire\ApprovalQueue;
use App\Livewire\Automations;
use App\Livewire\Health\Dashboard;
use App\Livewire\Mailboxes\Index;
use App\Livewire\Replies\Inbox;
use App\Livewire\Settings\ApiKeys;
use App\Livewire\Settings\Lookups;
use App\Livewire\Settings\Waterfall;
use App\Livewire\Settings\WaterfallPerformance;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {
    Route::get('approvals', ApprovalQueue::class)->name('approvals');
    Route::get('automations', Automations\Index::class)->name('automations.index');
    Route::get('automations/{automation}', Automations\Edit::class)->name('automations.edit');
    Route::get('mailboxes', Index::class)->name('mailboxes.index');
    Route::get('replies', Inbox::class)->name('replies.inbox');
    Route::get('health', Dashboard::class)->name('health');
    Route::get('leads', App\Livewire\Contacts\Index::class)->name('contacts.index');
    Route::get('activity', App\Livewire\Activity\Index::class)->name('activity.index');
    Route::get('settings/api-keys', ApiKeys::class)->name('settings.api-keys');
    Route::get('settings/waterfall', Waterfall::class)->name('settings.waterfall');
    // Served at /settings/spend, which is what the menu and the heading call
    // it: a URL is read by a person. The route name and the class keep the
    // code's word for it, exactly as the leads list does.
    Route::get('settings/spend', WaterfallPerformance::class)->name('settings.waterfall-performance');
    // The calls behind the totals on Spend. One row per provider call, so a
    // verdict can be argued with rather than only counted.
    Route::get('settings/lookups', Lookups::class)->name('settings.lookups');
    Route::get('mailboxes/connect', [GoogleOAuthController::class, 'redirect'])->name('mailboxes.connect');
    Route::get('oauth/google/callback', [GoogleOAuthController::class, 'callback'])->name('oauth.google.callback');
});

require __DIR__.'/auth.php';
