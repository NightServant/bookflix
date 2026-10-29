<?php

namespace Database\Seeders;

use App\Enums\MediaFamily;
use App\Enums\ReadingMode;
use App\Models\MediaType;
use Illuminate\Database\Seeder;

class MediaTypeSeeder extends Seeder
{
    /**
     * The media types of spec §2, keyed by slug.
     *
     * @var array<string, array{name: string, family: MediaFamily, reading_mode: ReadingMode}>
     */
    public const TYPES = [
        'novel' => ['name' => 'Novel', 'family' => MediaFamily::Prose, 'reading_mode' => ReadingMode::Reflowable],
        'light-novel' => ['name' => 'Light Novel', 'family' => MediaFamily::Prose, 'reading_mode' => ReadingMode::Reflowable],
        'nonfiction' => ['name' => 'Nonfiction', 'family' => MediaFamily::Prose, 'reading_mode' => ReadingMode::Reflowable],
        'comic-book' => ['name' => 'Comic Book', 'family' => MediaFamily::SequentialArt, 'reading_mode' => ReadingMode::Paged],
        'graphic-novel' => ['name' => 'Graphic Novel', 'family' => MediaFamily::SequentialArt, 'reading_mode' => ReadingMode::Paged],
        'manga' => ['name' => 'Manga', 'family' => MediaFamily::SequentialArt, 'reading_mode' => ReadingMode::Paged],
        'manhwa' => ['name' => 'Manhwa', 'family' => MediaFamily::SequentialArt, 'reading_mode' => ReadingMode::VerticalScroll],
        'manhua' => ['name' => 'Manhua', 'family' => MediaFamily::SequentialArt, 'reading_mode' => ReadingMode::VerticalScroll],
        'webcomic' => ['name' => 'Webcomic', 'family' => MediaFamily::SequentialArt, 'reading_mode' => ReadingMode::VerticalScroll],
        'picture-book' => ['name' => 'Picture Book', 'family' => MediaFamily::Illustrated, 'reading_mode' => ReadingMode::Paged],
        'visual-novel' => ['name' => 'Visual Novel', 'family' => MediaFamily::Interactive, 'reading_mode' => ReadingMode::Interactive],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TYPES as $slug => $attributes) {
            MediaType::updateOrCreate(['slug' => $slug], $attributes);
        }
    }
}
