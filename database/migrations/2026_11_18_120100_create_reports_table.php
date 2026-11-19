<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A reader's report of a review (P10-R9). A reader has at most one open report per target (partial unique index); `handled_by`
     * goes null if the moderator's account is purged.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('reportable');
            $table->enum('reason', ['spam', 'abuse', 'spoiler', 'copyright', 'other']);
            $table->text('details')->nullable();
            $table->enum('status', ['open', 'actioned', 'dismissed'])->default('open');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement("create unique index reports_open_unique on reports (reporter_id, reportable_type, reportable_id) where status = 'open'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
