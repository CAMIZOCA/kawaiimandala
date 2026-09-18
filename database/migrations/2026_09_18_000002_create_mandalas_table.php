<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandalas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('image_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->text('prompt')->nullable();
            $table->string('generation_source', 20)->default('manual');
            $table->string('generation_status', 20)->default('pending');
            $table->string('request_token', 64)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->text('generation_error')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandalas');
    }
};
