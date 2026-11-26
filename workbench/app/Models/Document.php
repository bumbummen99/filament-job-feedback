<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property bool $published
 */
final class Document extends Model
{
    protected $fillable = ['title', 'body', 'published'];

    protected $casts = ['published' => 'boolean'];
}
