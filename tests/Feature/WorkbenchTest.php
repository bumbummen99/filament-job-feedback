<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Workbench\App\Filament\Pages\JobFeedbackDemo;
use Workbench\App\Filament\Resources\Documents\Pages\CreateDocument;
use Workbench\App\Filament\Resources\Documents\Pages\EditDocument;
use Workbench\App\Models\Document;

uses(WithWorkbench::class, RefreshDatabase::class);

it('creates edits and deletes a document through the resource', function (): void {
    $this->get('/workbench/documents')->assertOk()->assertSee('Documents');
    Livewire::test(CreateDocument::class)
        ->fillForm(['title' => 'First draft', 'body' => 'One two', 'published' => true])
        ->call('create')->assertHasNoFormErrors();
    $document = Document::query()->sole();
    Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
        ->fillForm(['title' => 'Second draft', 'body' => 'One two three'])
        ->call('save')->assertHasNoFormErrors();
    expect($document->refresh()->title)->toBe('Second draft');
    Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
        ->callAction('delete');
    expect(Document::query()->exists())->toBeFalse();
});

it('renders the workbench action examples at the preview URL', function (): void {
    $this->get('/')
        ->assertRedirect('/workbench');

    $this->get('/workbench')
        ->assertOk()
        ->assertSee('fi-body', false)
        ->assertSee('Filament Job Feedback')
        ->assertSee('Dashboard')
        ->assertSee('Job Feedback Examples')
        ->assertSee('Global maintenance')
        ->assertSee('Report jobs')
        ->assertSee('Documents')
        ->assertDontSee('Rebuild search index');

    $this->get('/workbench/global-maintenance')
        ->assertOk()
        ->assertSee('Rebuild search index')
        ->assertSee('Simulate a lost update');

    $this->get('/workbench/report-jobs')->assertOk()->assertSee('Generate report');
});

it('simulates a lost completion in the workbench without a worker and can complete a replacement', function (): void {
    $this->freezeTime();

    $page = Livewire::test(JobFeedbackDemo::class)
        ->call('mountAction', 'simulateStaleClaim')
        ->assertSee('Drop the completion update')
        ->assertDontSee('Replacing a stale run')
        ->set('mountedActions.0.data.loseCompletion', true)
        ->call('callMountedAction')
        ->assertSee('Job running.')
        ->assertDontSee('No recent update');
    $subscription = array_key_first($page->get('jobFeedbackSubscriptions'));

    $this->travel(30)->seconds();
    $page->call('pollJobFeedback')
        ->assertDontSee('No recent update')
        ->assertNotNotified('Job run may be stale');
    $this->travel(1)->second();
    $page->call('pollJobFeedback')
        ->assertSee('No recent update')
        ->assertNotified('Job run may be stale')
        ->call('pollJobFeedback')
        ->assertNotNotified('Job run may be stale')
        ->call('mountStaleJobFeedbackReplacement', $subscription)
        ->assertSet('mountedActions.0.name', 'simulateStaleClaim')
        ->assertSeeInOrder(['Replacing a stale run', 'Starting a new run does not cancel the original job.', 'Drop the completion update', 'Start a new run'])
        ->set('mountedActions.0.data.loseCompletion', false)
        ->call('callMountedAction')
        ->call('pollJobFeedback')
        ->assertSee('No longer the active run. Outcome unknown.')
        ->assertSee('Job completed.');
});
