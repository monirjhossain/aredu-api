<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('lesson_id');
            $table->string('external_id')->nullable()->after('provider');
            $table->text('embed_url')->nullable()->after('external_id');
            $table->text('source_url')->nullable()->after('embed_url');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['provider', 'external_id', 'embed_url', 'source_url']);
        });
    }
};
