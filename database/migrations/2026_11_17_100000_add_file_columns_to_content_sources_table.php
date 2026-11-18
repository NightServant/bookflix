<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What the reader (Phase 9) needs to know about the stored file: whether it is there yet, how big it is, what it really is (sniffed, never
     * the client's word) and a checksum. `original_name` is for display only and never reaches a path. `external_key` makes an import
     * idempotent (e.g. `gutenberg:1342`).
     */
    public function up(): void
    {
        Schema::table('content_sources', function (Blueprint $table) {
            $table->enum('status', ['pending', 'ready', 'failed'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->string('original_name')->nullable();
            $table->string('external_key')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_sources', function (Blueprint $table) {
            $table->dropColumn(['status', 'failure_reason', 'byte_size', 'mime_type', 'checksum', 'original_name', 'external_key']);
        });
    }
};
