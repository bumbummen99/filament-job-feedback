<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $name
 * @property bool $eligible
 * @property bool $processed
 */
final class FeedbackRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'eligible', 'processed'];

    protected $casts = ['eligible' => 'boolean', 'processed' => 'boolean'];
}
