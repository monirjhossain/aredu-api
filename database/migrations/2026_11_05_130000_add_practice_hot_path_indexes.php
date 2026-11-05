<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive indexes only — based on verified hot query patterns:
 * - mock_tests: Reading/Listening student + public catalogs filter module + is_published (+ is_free_sample)
 * - writing_submissions: teacher queue where status = submitted order by submitted_at
 * - speaking_recordings: teacher queue where status = submitted order by submitted_at
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_tests', function (Blueprint $table) {
            $table->index(
                ['module', 'is_published', 'is_free_sample'],
                'mock_tests_module_published_sample_idx',
            );
        });

        Schema::table('writing_submissions', function (Blueprint $table) {
            $table->index(
                ['status', 'submitted_at'],
                'writing_submissions_status_submitted_idx',
            );
        });

        Schema::table('speaking_recordings', function (Blueprint $table) {
            $table->index(
                ['status', 'submitted_at'],
                'speaking_recordings_status_submitted_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('mock_tests', function (Blueprint $table) {
            $table->dropIndex('mock_tests_module_published_sample_idx');
        });

        Schema::table('writing_submissions', function (Blueprint $table) {
            $table->dropIndex('writing_submissions_status_submitted_idx');
        });

        Schema::table('speaking_recordings', function (Blueprint $table) {
            $table->dropIndex('speaking_recordings_status_submitted_idx');
        });
    }
};
