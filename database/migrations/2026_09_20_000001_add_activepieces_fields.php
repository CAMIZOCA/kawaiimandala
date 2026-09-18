<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mandala_flows', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('position');
            $table->string('flow_id', 64)->nullable()->after('name');
        });

        Schema::table('mandalas', function (Blueprint $table) {
            $table->string('callback_secret_hash', 64)->nullable()->after('request_token');
            $table->string('completed_token', 64)->nullable()->after('callback_secret_hash');
            $table->string('error_code', 40)->nullable()->after('generation_error');
            $table->timestamp('responded_at')->nullable()->after('requested_at');
            $table->string('original_image_path')->nullable()->after('image_path');
            $table->text('final_prompt')->nullable()->after('prompt');
        });

        // Until now the callback overwrote `prompt` with the flow's final prompt, and a retry
        // re-sent it as "extra notes". Keep it as `final_prompt`; `prompt` is for user notes only.
        DB::table('mandalas')
            ->where('generation_source', 'activepieces')
            ->whereNotNull('prompt')
            ->update(['final_prompt' => DB::raw('prompt'), 'prompt' => null]);
    }

    public function down(): void
    {
        Schema::table('mandalas', function (Blueprint $table) {
            $table->dropColumn(['callback_secret_hash', 'completed_token', 'error_code', 'responded_at', 'original_image_path', 'final_prompt']);
        });

        Schema::table('mandala_flows', function (Blueprint $table) {
            $table->dropColumn(['name', 'flow_id']);
        });
    }
};
