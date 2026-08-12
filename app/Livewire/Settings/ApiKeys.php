<?php

namespace App\Livewire\Settings;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ApiKeys extends Component
{
    public string $newKeyName = '';

    public ?string $plainTextKey = null;

    public function create(): void
    {
        $this->validate(['newKeyName' => ['required', 'string', 'max:255']]);

        $token = auth()->user()->createToken($this->newKeyName, ['ingest']);

        $this->plainTextKey = $token->plainTextToken;
        $this->newKeyName = '';
    }

    public function revoke(int $tokenId): void
    {
        auth()->user()->tokens()->where('id', $tokenId)->delete();
    }

    public function render()
    {
        return view('livewire.settings.api-keys', [
            'tokens' => auth()->user()->tokens()->latest()->get(),
        ]);
    }
}
