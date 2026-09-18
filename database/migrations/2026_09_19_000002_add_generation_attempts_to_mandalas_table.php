<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mandalas', function (Blueprint $table) {
            $table->unsignedSmallInteger('generation_attempts')->default(0)->after('generation_status');
        });
    }

    public function down(): void
    {
        Schema::table('mandalas', function (Blueprint $table) {
            $table->dropColumn('generation_attempts');
        });
    }
};
