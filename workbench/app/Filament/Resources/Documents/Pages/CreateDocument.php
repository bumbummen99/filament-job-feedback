<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Documents\Pages;

use Filament\Resources\Pages\CreateRecord;
use Workbench\App\Filament\Resources\Documents\DocumentResource;

final class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;
}
