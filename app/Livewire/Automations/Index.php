<?php

namespace App\Livewire\Automations;

use App\Models\Automation;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $newName = '';

    public string $newTag = '';

    public function updatedNewName(): void
    {
        if ($this->newTag === '' || $this->newTag === Str::slug(Str::beforeLast($this->newName, ' '))) {
            $this->newTag = Str::slug($this->newName);
        }
    }

    public function create(): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newTag' => ['required', 'string', 'max:255', 'unique:automations,tag'],
        ]);

        $automation = Automation::query()->create([
            'name' => $this->newName,
            'tag' => Str::slug($this->newTag),
            'active' => true,
        ]);

        $this->redirectRoute('automations.edit', ['automation' => $automation], navigate: true);
    }

    public function toggleActive(int $id): void
    {
        $automation = Automation::query()->findOrFail($id);
        $automation->update(['active' => ! $automation->active]);
    }

    public function render()
    {
        return view('livewire.automations.index', [
            'automations' => Automation::query()
                ->withCount(['steps', 'enrollments as active_enrollments_count' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
