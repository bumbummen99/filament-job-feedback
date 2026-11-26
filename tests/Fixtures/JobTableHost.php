<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\InteractsWithJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;
use SkyRaptor\FilamentJobFeedback\Actions\JobBulkAction;

final class JobTableHost extends Component implements HasActions, HasJobFeedback, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithJobFeedback;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(FeedbackRecord::query())
            ->columns([TextColumn::make('name')])
            ->defaultSort('id')
            ->recordActions([self::processRecordWith(JobAction::make('process'))->authorize('process-feedback-record')])
            ->toolbarActions([self::processRecordWith(JobBulkAction::make('processSelected'))
                ->allowStaleJobReplacement()
                ->authorizeIndividualRecords('process-feedback-record')]);
    }

    /**
     * @template T of JobAction|JobBulkAction
     *
     * @param  T  $action
     * @return T
     */
    public static function processRecordWith(JobAction|JobBulkAction $action): JobAction|JobBulkAction
    {
        return $action
            ->job(fn (FeedbackRecord $record): ProcessFeedbackRecord => new ProcessFeedbackRecord($record->id), discover: true)
            ->onJobCompleted(fn (FeedbackRecord $record) => Notification::make()->title("Processed {$record->name}")->success()->send());
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->table }}
                <x-filament-actions::modals />
                @include('filament-job-feedback::status')
            </div>
            BLADE;
    }
}
