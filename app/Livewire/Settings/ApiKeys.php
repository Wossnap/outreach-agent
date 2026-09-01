<?php

namespace App\Livewire\Settings;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ApiKeys extends Component
{
    /**
     * What each ability lets a key do, shown on the form so the choice is not
     * made blind.
     */
    public const ABILITIES = [
        'read' => 'Read contacts, replies, opt-outs, drafts, stats and activity',
        'write' => 'Push contacts, edit and reject drafts, manage automations and mailboxes',
        'approve' => 'Approve a draft so it sends (also needs enabling in config)',
    ];

    public string $newKeyName = '';

    /** @var array<int, string> */
    public array $newKeyAbilities = ['read'];

    public ?string $plainTextKey = null;

    public function create(): void
    {
        $this->validate([
            'newKeyName' => ['required', 'string', 'max:255'],
            'newKeyAbilities' => ['required', 'array', 'min:1'],
            'newKeyAbilities.*' => ['in:'.implode(',', array_keys(self::ABILITIES))],
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
            'abilities' => self::ABILITIES,
        ]);
    }
}
