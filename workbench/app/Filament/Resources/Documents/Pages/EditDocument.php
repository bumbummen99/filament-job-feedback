<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Documents\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\InteractsWithJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;
use Workbench\App\Filament\Resources\Documents\DocumentResource;

final class EditDocument extends EditRecord implements HasJobFeedback
{
    use InteractsWithJobFeedback;

    protected static string $resource = DocumentResource::class;

    /** @return list<JobAction|DeleteAction> */
    protected function getHeaderActions(): array
    {
        return [
            DocumentResource::configureWordCount(JobAction::make('countDocumentWords')
                ->label('Count words')
                ->record($this->getRecord()))
                ->authorize('countWords', $this->getRecord()),
            DeleteAction::make(),
        ];
    }
}
