<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Full IELTS mock exam orchestrator.
 * Additive only: new tables + context_key columns on existing attempt tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('full_mock_exams', function (Blueprint $table) {
            $table->id();
            $table->string('exam_type', 20);
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('instructions')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_free')->default(false);
            $table->boolean('allow_resume')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['exam_type', 'is_published']);
        });

        Schema::create('full_mock_exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('full_mock_exam_id')->constrained()->cascadeOnDelete();
            $table->string('skill', 20);
            $table->string('title');
            $table->unsignedInteger('position');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('mock_test_id')->nullable()->constrained('mock_tests')->restrictOnDelete();
            $table->foreignId('writing_task_id')->nullable()->constrained('writing_tasks')->restrictOnDelete();
            $table->foreignId('speaking_task_id')->nullable()->constrained('speaking_tasks')->restrictOnDelete();
            $table->timestamps();

            $table->index(['full_mock_exam_id', 'position']);
        });

        Schema::create('full_mock_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('full_mock_exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->unsignedInteger('current_position')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'full_mock_exam_id', 'status'], 'fm_sessions_user_exam_status_idx');
        });

        Schema::create('full_mock_session_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('full_mock_session_id')->constrained('full_mock_sessions')->cascadeOnDelete();
            $table->foreignId('full_mock_exam_section_id')->constrained('full_mock_exam_sections')->restrictOnDelete();
            $table->string('skill', 20);
            $table->string('title');
            $table->unsignedInteger('position');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 20)->default('locked');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('must_submit_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('test_attempt_id')->nullable()->constrained('test_attempts')->nullOnDelete();
            $table->foreignId('writing_submission_id')->nullable()->constrained('writing_submissions')->nullOnDelete();
            $table->foreignId('speaking_recording_id')->nullable()->constrained('speaking_recordings')->nullOnDelete();
            $table->timestamps();

            $table->unique(['full_mock_session_id', 'position'], 'fm_session_sections_position_uidx');
            $table->index(['full_mock_session_id', 'status']);
        });

        Schema::table('test_attempts', function (Blueprint $table) {
            $table->string('context_key', 64)->default('practice')->after('status');
            $table->index(['user_id', 'mock_test_id', 'context_key', 'status'], 'attempts_context_status_idx');
        });

        Schema::table('writing_submissions', function (Blueprint $table) {
            $table->string('context_key', 64)->default('practice')->after('status');
            $table->unique(['user_id', 'writing_task_id', 'context_key'], 'writing_submissions_context_uidx');
        });

        Schema::table('speaking_recordings', function (Blueprint $table) {
            $table->string('context_key', 64)->default('practice')->after('status');
            $table->index(['user_id', 'speaking_task_id', 'context_key'], 'speaking_recordings_context_idx');
        });
    }

    public function down(): void
    {
        Schema::table('speaking_recordings', function (Blueprint $table) {
            $table->dropIndex('speaking_recordings_context_idx');
            $table->dropColumn('context_key');
        });

        Schema::table('writing_submissions', function (Blueprint $table) {
            $table->dropUnique('writing_submissions_context_uidx');
            $table->dropColumn('context_key');
        });

        Schema::table('test_attempts', function (Blueprint $table) {
            $table->dropIndex('attempts_context_status_idx');
            $table->dropColumn('context_key');
        });

        Schema::dropIfExists('full_mock_session_sections');
        Schema::dropIfExists('full_mock_sessions');
        Schema::dropIfExists('full_mock_exam_sections');
        Schema::dropIfExists('full_mock_exams');
    }
};
