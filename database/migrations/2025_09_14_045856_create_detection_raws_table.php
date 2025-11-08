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
Schema::create('detection_raws', function (Blueprint $table) {
    $table->id();
    $table->foreignId('analysis_id')->constrained()->onDelete('cascade');
    $table->string('class');
    $table->float('confidence');
    $table->json('bbox');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detection_raws');
    }
};
