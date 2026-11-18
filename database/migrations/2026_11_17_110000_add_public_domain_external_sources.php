<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two public-domain catalogues are sources like the others. They are added here as well as by the seeder so a database that was
     * seeded earlier gets them on deploy; a row that exists is left as it is, and both start disabled.
     */
    public function up(): void
    {
        $sources = [
            'gutenberg' => ['Project Gutenberg', 'https://www.gutenberg.org', 'Public-domain books from Project Gutenberg (gutenberg.org).'],
            'standardebooks' => ['Standard Ebooks', 'https://standardebooks.org', 'Public-domain ebooks from Standard Ebooks (standardebooks.org).'],
        ];

        foreach ($sources as $key => [$name, $baseUrl, $attribution]) {
            if (DB::table('external_sources')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('external_sources')->insert([
                'key' => $key,
                'name' => $name,
                'base_url' => $baseUrl,
                'enabled' => false,
                'attribution' => $attribution,
                'cover_policy' => 'hotlink',
                'display_policy' => json_encode(['show_attribution' => true, 'link_back' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sources may already own imported media, so the rows are kept.
    }
};
