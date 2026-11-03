<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at'], 'lesson_progress_user_updated_idx');
            $table->index(['user_id', 'completed_at'], 'lesson_progress_user_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex('lesson_progress_user_updated_idx');
            $table->dropIndex('lesson_progress_user_completed_idx');
        });
    }
};
