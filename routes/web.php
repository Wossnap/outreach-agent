<?php

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
});

require __DIR__.'/auth.php';
