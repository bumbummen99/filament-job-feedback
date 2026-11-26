<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\App\Models\Document;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([
            ['Getting started', 'Select this published document to calculate its word count.', true],
            ['Release notes', 'Try selecting this document alongside the getting started guide.', true],
            ['Draft announcement', 'Publish this draft before counting its words.', false],
        ] as [$title, $body, $published]) {
            Document::query()->firstOrCreate(['title' => $title], ['body' => $body, 'published' => $published]);
        }
    }
}
