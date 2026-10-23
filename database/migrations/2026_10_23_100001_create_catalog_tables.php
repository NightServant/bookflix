<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->enum('family', ['prose', 'sequential_art', 'illustrated', 'interactive']);
            $table->enum('reading_mode', ['reflowable', 'paged', 'vertical_scroll', 'interactive']);
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('media_type_id')->index()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->jsonb('alternative_titles')->default('[]');
            $table->text('description')->nullable();
            $table->text('cover_url')->nullable();
            $table->string('cover_path')->nullable();
            $table->date('published_on')->nullable();
            $table->smallInteger('publication_year')->nullable()->index();
            $table->string('language', 8)->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->string('volume_number', 16)->nullable();
            $table->string('chapter_number', 16)->nullable();
            $table->string('issue_number', 16)->nullable();
            $table->string('format')->nullable();
            $table->enum('age_rating', ['all', 'teen', 'mature', 'adult', 'unknown'])->default('unknown');
            $table->jsonb('content_warnings')->default('[]');
            $table->enum('status', ['upcoming', 'ongoing', 'completed', 'hiatus', 'cancelled', 'unknown'])->default('unknown')->index();
            $table->integer('page_count')->nullable();
            $table->integer('chapter_count')->nullable();
            $table->decimal('average_rating', 4, 2)->nullable();
            $table->integer('ratings_count')->default(0);
            $table->text('search_text')->default('');
            $table->jsonb('locked_fields')->default('[]');
            $table->foreignId('merged_into_id')->nullable()->index()->constrained('media')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement('create index media_title_trgm on media using gin (title gin_trgm_ops)');

        Schema::create('creators', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->jsonb('alternative_names')->default('[]');
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        Schema::create('media_creators', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('creator_id')->index()->constrained()->cascadeOnDelete();
            $table->enum('role', ['author', 'artist', 'illustrator', 'writer', 'penciller', 'inker', 'colorist', 'translator', 'developer']);
            $table->smallInteger('position')->default(0);
            $table->timestamps();
            $table->primary(['media_id', 'creator_id', 'role']);
        });

        Schema::create('publishers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('media_publishers', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('publisher_id')->index()->constrained()->cascadeOnDelete();
            $table->string('region')->nullable();
            $table->timestamps();
            $table->primary(['media_id', 'publisher_id']);
        });

        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['upcoming', 'ongoing', 'completed', 'hiatus', 'cancelled', 'unknown'])->default('unknown');
            $table->timestamps();
        });

        Schema::create('media_series', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('series_id')->index()->constrained('series')->cascadeOnDelete();
            $table->decimal('position', 8, 2)->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
            $table->primary(['media_id', 'series_id']);
        });

        Schema::create('media_relations', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('related_media_id')->index()->constrained('media')->cascadeOnDelete();
            $table->enum('relation', ['sequel', 'prequel', 'adaptation', 'spin_off', 'side_story', 'alternate_version', 'same_universe']);
            $table->timestamps();
            $table->primary(['media_id', 'related_media_id', 'relation']);
        });

        foreach (['genre' => 'genres', 'theme' => 'themes', 'tag' => 'tags', 'character' => 'characters'] as $singular => $plural) {
            Schema::create($plural, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });

            Schema::create("media_{$plural}", function (Blueprint $table) use ($singular, $plural) {
                $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
                $table->foreignId("{$singular}_id")->index()->constrained($plural)->cascadeOnDelete();

                if ($singular === 'character') {
                    $table->enum('role', ['main', 'supporting'])->default('supporting');
                }

                $table->timestamps();
                $table->primary(['media_id', "{$singular}_id"]);
            });
        }

        Schema::create('external_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('base_url');
            $table->boolean('enabled')->default(false);
            $table->text('attribution');
            $table->enum('cover_policy', ['hotlink', 'cache', 'none'])->default('none');
            $table->jsonb('display_policy')->default('{}');
            $table->jsonb('sync_cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media_external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->index()->constrained('media')->cascadeOnDelete();
            $table->foreignId('external_source_id')->constrained()->restrictOnDelete();
            $table->string('external_id');
            $table->text('source_url')->nullable();
            $table->char('payload_hash', 64);
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamp('last_synced_at')->useCurrent();
            $table->timestamps();
            $table->unique(['external_source_id', 'external_id']);
        });

        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_source_id')->constrained()->cascadeOnDelete();
            $table->enum('mode', ['full', 'incremental', 'single']);
            $table->enum('status', ['running', 'succeeded', 'partial', 'failed'])->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->jsonb('counts')->default('{"fetched":0,"created":0,"updated":0,"merged":0,"skipped":0,"failed":0}');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['external_source_id', 'started_at']);
        });

        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->index()->constrained('media')->cascadeOnDelete();
            $table->foreignId('duplicate_media_id')->index()->constrained('media')->cascadeOnDelete();
            $table->float('score');
            $table->enum('status', ['pending', 'merged', 'dismissed'])->default('pending')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['media_id', 'duplicate_media_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['duplicate_candidates', 'ingestion_runs', 'media_external_ids', 'external_sources'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (['characters', 'tags', 'themes', 'genres'] as $plural) {
            Schema::dropIfExists("media_{$plural}");
            Schema::dropIfExists($plural);
        }

        foreach (['media_relations', 'media_series', 'series', 'media_publishers', 'publishers', 'media_creators', 'creators', 'media', 'media_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
