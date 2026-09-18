<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('animal_theme', 120);
            $table->text('introduction')->nullable();
            $table->string('author_name')->nullable();
            $table->text('creator_description')->nullable();
            $table->text('copyright_text')->nullable();
            $table->unsignedSmallInteger('copyright_year')->nullable();
            $table->string('website_url')->nullable();
            $table->unsignedSmallInteger('mandala_count')->default(22);
            $table->string('status', 30)->default('draft');
            $table->boolean('ai_queue_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
