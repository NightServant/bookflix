<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `job_key` is the id of the queued job that opened the run. A retry of that job finds its row by it instead of asking the service to start the
     * job a second time and writing a second row.
     */
    public function up(): void
    {
        Schema::table('ml_job_runs', function (Blueprint $table) {
            $table->uuid('job_key')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ml_job_runs', function (Blueprint $table) {
            $table->dropColumn('job_key');
        });
    }
};
