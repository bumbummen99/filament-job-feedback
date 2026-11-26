<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Documents\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\InteractsWithJobFeedback;
use Workbench\App\Filament\Resources\Documents\DocumentResource;

final class ListDocuments extends ListRecords implements HasJobFeedback
{
    use InteractsWithJobFeedback;

    protected static string $resource = DocumentResource::class;

    /** @return list<CreateAction> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getFooter(): ?View
    {
        return view()->file(__DIR__.'/../../../../../resources/views/document-job-feedback.blade.php');
    }
}
