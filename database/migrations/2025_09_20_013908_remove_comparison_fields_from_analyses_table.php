<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropColumn([
                'original_image_path_after',
                'processed_image_path_after',
                'detection_results_after',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->string('original_image_path_after')->nullable()->after('detection_results');
            $table->string('processed_image_path_after')->nullable()->after('original_image_path_after');
            $table->json('detection_results_after')->nullable()->after('processed_image_path_after');
        });
    }
};
