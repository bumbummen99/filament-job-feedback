<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Workbench\App\Models\Document;
use Workbench\App\Models\User;

final class BrowserSeeder extends Seeder
{
    public function run(): void
    {
        Document::query()->create([
            'title' => 'Support handbook',
            'body' => 'Count the words in this document independently.',
            'published' => true,
        ]);

        foreach (['Admin One', 'Admin Two', 'Viewer'] as $name) {
            User::query()->create([
                'name' => $name,
                'email' => Str::slug($name).'@example.test',
                'password' => Str::random(48),
            ]);
        }
    }
}
