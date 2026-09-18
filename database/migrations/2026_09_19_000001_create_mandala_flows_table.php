<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandala_flows', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('position')->unique();
            $table->string('flow_url', 2048)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandala_flows');
    }
};
