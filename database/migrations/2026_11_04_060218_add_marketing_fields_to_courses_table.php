<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedInteger('learners_count')->nullable()->after('duration_minutes');
            $table->date('class_starts_on')->nullable()->after('learners_count');
            $table->unsignedInteger('batch_number')->nullable()->after('class_starts_on');
            $table->unsignedInteger('seats_left')->nullable()->after('batch_number');
            $table->date('offer_ends_on')->nullable()->after('seats_left');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'learners_count',
                'class_starts_on',
                'batch_number',
                'seats_left',
                'offer_ends_on',
            ]);
        });
    }
};
