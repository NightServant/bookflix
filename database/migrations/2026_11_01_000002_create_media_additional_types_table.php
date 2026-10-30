<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A book can be more than one type at once (a comic book series collected as a graphic novel, a novel told partly in pictures). The main type
     * stays on `media.media_type_id`, which decides the reading mode and the Dewey shelf; the others are listed here, so a type filter finds them too.
     */
    public function up(): void
    {
        Schema::create('media_additional_types', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('media_type_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['media_id', 'media_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_additional_types');
    }
};
