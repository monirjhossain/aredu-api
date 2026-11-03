<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('course_categories')->restrictOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('exam_type', 20);
            $table->decimal('price', 10, 2);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('course_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('can_manage_content')->default(false);
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
        });

        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'position']);
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->unsignedInteger('position');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('is_free_preview')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_section_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('course_teachers');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_categories');
    }
};
