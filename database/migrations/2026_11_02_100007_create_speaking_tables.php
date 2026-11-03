<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speaking_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('part');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('preparation_seconds')->default(0);
            $table->unsignedInteger('response_seconds')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('part');
        });

        Schema::create('speaking_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speaking_task_id')->constrained()->restrictOnDelete();
            $table->text('prompt');
            $table->text('cue_card')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['speaking_task_id', 'position']);
        });

        Schema::create('speaking_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speaking_task_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 20)->default('uploaded');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'speaking_task_id']);
        });

        Schema::create('speaking_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('speaking_recording_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->decimal('fluency', 3, 1);
            $table->decimal('lexical', 3, 1);
            $table->decimal('grammar', 3, 1);
            $table->decimal('pronunciation', 3, 1);
            $table->decimal('overall_band', 3, 1);
            $table->text('feedback')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speaking_evaluations');
        Schema::dropIfExists('speaking_recordings');
        Schema::dropIfExists('speaking_questions');
        Schema::dropIfExists('speaking_tasks');
    }
};
