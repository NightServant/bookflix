<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Laravel's record of a job it asked the ML service to run (D-25): `run_id` is the service's id once it accepted the job, `status` follows the
     * service's reports, and a failed run keeps its error. The admin models page lists these.
     */
    public function up(): void
    {
        Schema::create('ml_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('run_id')->nullable()->index();
            $table->string('status')->default('queued');
            $table->jsonb('params')->default('{}');
            $table->jsonb('metrics')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->index(['name', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ml_job_runs');
    }
};
