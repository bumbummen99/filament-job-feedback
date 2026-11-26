<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Workbench\App\Models\Document;

final class DocumentPolicy
{
    public function viewAny(?Authenticatable $actor): bool
    {
        return app()->environment('local', 'testing');
    }

    public function view(?Authenticatable $actor, Document $record): bool
    {
        return $this->viewAny($actor);
    }

    public function create(?Authenticatable $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(?Authenticatable $actor, Document $record): bool
    {
        return $this->view($actor, $record);
    }

    public function delete(?Authenticatable $actor, Document $record): bool
    {
        return $this->view($actor, $record);
    }

    public function countAnyWords(?Authenticatable $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function countWords(?Authenticatable $actor, Document $record): bool
    {
        return $this->view($actor, $record) && $record->published;
    }
}
