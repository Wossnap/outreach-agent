<?php

namespace App\Livewire\Settings;

use App\Models\ApiKey;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ApiKeys extends Component
{
    public string $newKeyName = '';

    /** @var array<int, string> */
    public array $newKeyAbilities = ['read'];

    public ?string $plainTextKey = null;

    public function create(): void
    {
        $this->validate([
            'newKeyName' => ['required', 'string', 'max:255'],
            'newKeyAbilities' => ['required', 'array', 'min:1'],
            'newKeyAbilities.*' => ['in:'.implode(',', array_keys(ApiKey::abilities()))],
        ], [
            'newKeyAbilities.required' => 'Pick at least one thing this key may do.',
        ]);

        $token = auth()->user()->createToken($this->newKeyName, array_values($this->newKeyAbilities));

        $this->plainTextKey = $token->plainTextToken;
        $this->newKeyName = '';
        $this->newKeyAbilities = ['read'];
    }

    public function revoke(int $tokenId): void
    {
        auth()->user()->tokens()->where('id', $tokenId)->delete();
    }

    public function render()
    {
        return view('livewire.settings.api-keys', [
            'tokens' => auth()->user()->tokens()->latest()->get(),
            'abilities' => ApiKey::abilities(),
        ]);
    }
}
