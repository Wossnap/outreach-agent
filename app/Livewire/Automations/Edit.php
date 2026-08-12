<?php

namespace App\Livewire\Automations;

use App\Models\Automation;
use App\Models\SequenceStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public Automation $automation;

    public string $name = '';

    public string $tag = '';

    public string $description = '';

    public bool $active = true;

    /** @var array<int, array{id: ?int, delay_days: int, delay_hours: int, drafting_instructions: string, active: bool}> */
    public array $steps = [];

    public function mount(Automation $automation): void
    {
        $this->automation = $automation;
        $this->name = $automation->name;
        $this->tag = $automation->tag;
        $this->description = (string) $automation->description;
        $this->active = $automation->active;
        $this->steps = $automation->steps()->get()->map(fn (SequenceStep $step) => [
            'id' => $step->id,
            'delay_days' => $step->delay_days,
            'delay_hours' => $step->delay_hours,
            'drafting_instructions' => $step->drafting_instructions,
            'active' => $step->active,
        ])->all();

        if ($this->steps === []) {
            $this->addStep();
        }
    }

    public function addStep(): void
    {
        $this->steps[] = [
            'id' => null,
            'delay_days' => count($this->steps) === 0 ? 0 : 3,
            'delay_hours' => 0,
            'drafting_instructions' => '',
            'active' => true,
        ];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->steps[$index], $this->steps[$target])) {
            return;
        }

        [$this->steps[$index], $this->steps[$target]] = [$this->steps[$target], $this->steps[$index]];
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'tag' => ['required', 'string', 'max:255', 'unique:automations,tag,'.$this->automation->id],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.delay_days' => ['required', 'integer', 'min:0', 'max:365'],
            'steps.*.delay_hours' => ['required', 'integer', 'min:0', 'max:23'],
            'steps.*.drafting_instructions' => ['required', 'string'],
        ], attributes: [
            'steps.*.drafting_instructions' => 'drafting instructions',
        ]);

        DB::transaction(function () {
            $this->automation->update([
                'name' => $this->name,
                'tag' => Str::slug($this->tag),
                'description' => $this->description ?: null,
                'active' => $this->active,
            ]);

            $keptIds = [];

            foreach (array_values($this->steps) as $position => $step) {
                $model = $step['id']
                    ? $this->automation->steps()->whereKey($step['id'])->first()
                    : null;

                $attributes = [
                    'position' => $position + 1,
                    'delay_days' => (int) $step['delay_days'],
                    'delay_hours' => (int) $step['delay_hours'],
                    'drafting_instructions' => $step['drafting_instructions'],
                    'active' => (bool) $step['active'],
                ];

                // Two-phase position write below avoids unique(automation_id, position)
                // collisions while reordering.
                if ($model) {
                    $model->update(array_merge($attributes, ['position' => $position + 1001]));
                } else {
                    $model = $this->automation->steps()->create(array_merge($attributes, ['position' => $position + 1001]));
                }

                $keptIds[] = $model->id;
            }

            $this->automation->steps()->whereNotIn('id', $keptIds)->delete();

            foreach ($this->automation->steps()->orderBy('position')->get() as $i => $model) {
                $model->update(['position' => $i + 1]);
            }
        });

        $this->mount($this->automation->refresh());
        $this->dispatch('saved');
    }

    public function render()
    {
        return view('livewire.automations.edit');
    }
}
