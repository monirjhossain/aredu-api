<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writing_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('exam_type', 20);
            $table->string('task_type', 32);
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->text('prompt');
            $table->string('image_path')->nullable();
            $table->unsignedInteger('recommended_minutes')->default(20);
            $table->unsignedInteger('min_words')->default(150);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['exam_type', 'task_type']);
        });

        Schema::create('writing_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writing_task_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->longText('body')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('writing_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writing_submission_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->decimal('task_response', 3, 1);
            $table->decimal('coherence', 3, 1);
            $table->decimal('lexical', 3, 1);
            $table->decimal('grammar', 3, 1);
            $table->decimal('overall_band', 3, 1);
            $table->text('feedback')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writing_evaluations');
        Schema::dropIfExists('writing_submissions');
        Schema::dropIfExists('writing_tasks');
    }
};
