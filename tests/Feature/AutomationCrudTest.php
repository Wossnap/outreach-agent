<?php

namespace Tests\Feature;

use App\Livewire\Automations\Edit;
use App\Livewire\Automations\Index;
use App\Models\Automation;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_require_auth(): void
    {
        $this->get('/automations')->assertRedirect('/login');
    }

    public function test_can_create_automation(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class)
            ->set('newName', 'SEO Backlinks')
            ->set('newTag', 'seo-backlinks')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('automations', ['tag' => 'seo-backlinks', 'name' => 'SEO Backlinks']);
    }

    public function test_can_edit_automation_with_steps(): void
    {
        $this->actingAs(User::factory()->create());
        $automation = Automation::factory()->create();

        Livewire::test(Edit::class, ['automation' => $automation])
            ->set('steps.0.drafting_instructions', 'Write the first email.')
            ->call('addStep')
            ->set('steps.1.delay_days', 4)
            ->set('steps.1.drafting_instructions', 'Write a follow-up.')
            ->call('save')
            ->assertHasNoErrors();

        $steps = $automation->steps()->get();
        $this->assertCount(2, $steps);
        $this->assertSame([1, 2], $steps->pluck('position')->all());
        $this->assertSame(4, $steps[1]->delay_days);
    }

    public function test_a_file_chosen_but_not_attached_goes_on_with_the_save(): void
    {
        // Choosing a file and pressing "Save automation" is the natural thing
        // to do, and it used to drop the file on the floor: only the separate
        // "Attach" button stored it, and an unsaved step could not take one at
        // all. Now the save attaches it, to a step that gets its id in the
        // same save.
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $automation = Automation::factory()->create();

        Livewire::test(Edit::class, ['automation' => $automation])
            ->set('steps.0.drafting_instructions', 'Write the first email.')
            ->call('addStep')
            ->set('steps.1.drafting_instructions', 'Follow up.')
            ->set('newAttachment.1', UploadedFile::fake()->createWithContent('deck.pdf', '%PDF-1.4'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('newAttachment', [])
            ->assertSee('deck.pdf');

        $steps = $automation->steps()->orderBy('position')->get();
        $this->assertSame([], $steps[0]->attachmentList());
        $this->assertSame('deck.pdf', $steps[1]->attachmentList()[0]['filename']);
        Storage::disk('local')->assertExists($steps[1]->attachmentList()[0]['path']);

        // Coming back to the page shows it.
        Livewire::test(Edit::class, ['automation' => $automation->fresh()])->assertSee('deck.pdf');
    }

    public function test_reordering_steps_keeps_unique_positions(): void
    {
        $this->actingAs(User::factory()->create());
        $automation = Automation::factory()->create();
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 1, 'drafting_instructions' => 'First']);
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 2, 'drafting_instructions' => 'Second']);

        Livewire::test(Edit::class, ['automation' => $automation])
            ->call('moveStep', 0, 1)
            ->call('save')
            ->assertHasNoErrors();

        $steps = $automation->steps()->orderBy('position')->get();
        $this->assertSame('Second', $steps[0]->drafting_instructions);
        $this->assertSame('First', $steps[1]->drafting_instructions);
    }

    public function test_removing_a_step_deletes_it(): void
    {
        $this->actingAs(User::factory()->create());
        $automation = Automation::factory()->create();
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 1]);
        SequenceStep::factory()->create(['automation_id' => $automation->id, 'position' => 2]);

        Livewire::test(Edit::class, ['automation' => $automation])
            ->call('removeStep', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $automation->steps()->count());
    }
}
