<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Documents;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;
use SkyRaptor\FilamentJobFeedback\Actions\JobBulkAction;
use UnitEnum;
use Workbench\App\Filament\Resources\Documents\Pages\CreateDocument;
use Workbench\App\Filament\Resources\Documents\Pages\EditDocument;
use Workbench\App\Filament\Resources\Documents\Pages\ListDocuments;
use Workbench\App\Filament\Resources\Documents\Pages\ViewDocument;
use Workbench\App\Jobs\CountDocumentWords;
use Workbench\App\Jobs\StaleClaimDemoJob;
use Workbench\App\Models\Document;

final class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = 'Job Feedback Examples';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            Toggle::make('published')->helperText('Only published documents can be queued for a word count.'),
            TextInput::make('word_count')->readOnly()->dehydrated(false)->visibleOn('edit')->placeholder('Not counted'),
            Textarea::make('body')->required()->rows(8)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            TextEntry::make('word_count')->placeholder('Not counted'),
            TextEntry::make('body')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                IconColumn::make('published')->boolean(),
                TextColumn::make('word_count')->placeholder('Not counted'),
            ])
            ->defaultSort('id')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::configureWordCount(JobAction::make('countDocumentWords')->label('Count words'))
                    ->authorize('countWords'),
                self::configureLostUpdateSimulation(JobAction::make('simulateLostUpdate')->label('Simulate lost update'))
                    ->authorize('countWords'),
            ])
            ->toolbarActions([
                self::configureWordCount(JobBulkAction::make('countSelectedWords')->label('Count words for selected documents'))
                    ->authorize('countAnyWords', Document::class)
                    ->authorizeIndividualRecords('countWords'),
                self::configureLostUpdateSimulation(JobBulkAction::make('simulateLostUpdates')->label('Simulate lost updates for selected documents'))
                    ->authorize('run-workbench-jobs')
                    ->authorizeIndividualRecords('countWords'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'create' => CreateDocument::route('/create'),
            'view' => ViewDocument::route('/{record}'),
            'edit' => EditDocument::route('/{record}/edit'),
        ];
    }

    /**
     * @template T of JobAction|JobBulkAction
     *
     * @param  T  $action
     * @return T
     */
    public static function configureWordCount(JobAction|JobBulkAction $action): JobAction|JobBulkAction
    {
        return $action
            ->icon(Heroicon::ArrowPath)
            ->requiresConfirmation()
            ->job(fn (Document $record): CountDocumentWords => new CountDocumentWords(
                $record->id,
                config()->integer('workbench.job_feedback_pause', 6),
            ), discover: true)
            ->onJobCompleted(function (Document $record, object $livewire): void {
                if ($livewire instanceof EditDocument) {
                    $record->refresh();
                    $livewire->refreshFormData(['word_count']);
                }

                Notification::make()->title("Word count updated for {$record->title}")->success()->send();
            });
    }

    /**
     * @template T of JobAction|JobBulkAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configureLostUpdateSimulation(JobAction|JobBulkAction $action): JobAction|JobBulkAction
    {
        return $action
            ->icon(Heroicon::ExclamationTriangle)
            ->color('warning')
            ->allowStaleJobReplacement()
            ->modalDescription('Like word counting, this demo only accepts published documents. It runs without a worker and does not change any documents.')
            ->schema([
                Toggle::make('loseCompletion')
                    ->label('Drop the completion updates')
                    ->helperText('Leave this on to make the runs become stale. Turn it off when starting replacements to let them complete normally.')
                    ->default(true),
            ])
            ->job(fn (Document $record, array $data): StaleClaimDemoJob => (new StaleClaimDemoJob($record->id))
                ->onConnection(($data['loseCompletion'] ?? true) ? StaleClaimDemoJob::SilentConnection : 'sync'), discover: true)
            ->onJobCompleted(function (Document $record): void {
                Notification::make()->title("Demo job completed for {$record->title}")->success()->send();
            });
    }
}
