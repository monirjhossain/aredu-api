<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_tests', function (Blueprint $table) {
            $table->id();
            $table->string('module', 20);
            $table->string('exam_type', 20);
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('instructions')->nullable();
            $table->unsignedInteger('duration_seconds');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('question_audios', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('path');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        Schema::create('mock_test_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_test_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->unsignedInteger('position');
            $table->foreignId('question_audio_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['mock_test_id', 'position']);
        });

        Schema::create('question_passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_test_section_id')->constrained()->restrictOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['mock_test_section_id', 'position']);
        });

        Schema::create('question_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_test_section_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_passage_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->text('instructions')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['mock_test_section_id', 'position']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_group_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20)->nullable();
            $table->text('content');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['question_group_id', 'position']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_group_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->unsignedInteger('position');
            $table->decimal('marks', 8, 2)->default(1);
            $table->foreignId('correct_option_id')->nullable()->constrained('question_options')->restrictOnDelete();
            $table->timestamps();

            $table->index(['question_group_id', 'position']);
        });

        Schema::create('question_accepted_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('answer_text');
            $table->timestamps();

            $table->index('question_id');
        });

        Schema::create('test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_test_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('must_submit_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('status', 20)->default('in_progress');
            $table->timestamps();

            $table->index(['user_id', 'mock_test_id', 'status'], 'attempts_user_test_status_idx');
        });

        Schema::create('test_attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_option_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('answer_text')->nullable();
            $table->timestamps();

            $table->unique(['test_attempt_id', 'question_id'], 'attempt_answers_unique');
        });

        Schema::create('test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_attempt_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('raw_score', 8, 2);
            $table->decimal('max_score', 8, 2);
            $table->decimal('band_score', 3, 1)->nullable();
            $table->timestamps();
        });

        Schema::create('band_score_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('module', 20);
            $table->string('exam_type', 20);
            $table->unsignedSmallInteger('raw_score');
            $table->decimal('band_score', 3, 1);
            $table->timestamps();

            $table->unique(['module', 'exam_type', 'raw_score'], 'band_conversion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('band_score_conversions');
        Schema::dropIfExists('test_results');
        Schema::dropIfExists('test_attempt_answers');
        Schema::dropIfExists('test_attempts');
        Schema::dropIfExists('question_accepted_answers');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('question_groups');
        Schema::dropIfExists('question_passages');
        Schema::dropIfExists('mock_test_sections');
        Schema::dropIfExists('question_audios');
        Schema::dropIfExists('mock_tests');
    }
};
