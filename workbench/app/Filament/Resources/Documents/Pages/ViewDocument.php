<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Documents\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Workbench\App\Filament\Resources\Documents\DocumentResource;

final class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    /** @return list<EditAction> */
    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
