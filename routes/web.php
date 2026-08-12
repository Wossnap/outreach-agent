<?php

use App\Http\Controllers\GoogleOAuthController;
use App\Livewire\Automations;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {
    Route::get('approvals', App\Livewire\ApprovalQueue::class)->name('approvals');
    Route::get('automations', Automations\Index::class)->name('automations.index');
    Route::get('automations/{automation}', Automations\Edit::class)->name('automations.edit');
    Route::get('mailboxes', App\Livewire\Mailboxes\Index::class)->name('mailboxes.index');
    Route::get('replies', App\Livewire\Replies\Inbox::class)->name('replies.inbox');
    Route::get('health', App\Livewire\Health\Dashboard::class)->name('health');
    Route::get('mailboxes/connect', [GoogleOAuthController::class, 'redirect'])->name('mailboxes.connect');
    Route::get('oauth/google/callback', [GoogleOAuthController::class, 'callback'])->name('oauth.google.callback');
});

require __DIR__.'/auth.php';
