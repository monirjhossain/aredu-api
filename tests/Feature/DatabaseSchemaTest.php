<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_tables_exist_after_migrate(): void
    {
        $tables = [
            'roles',
            'permissions',
            'role_user',
            'permission_role',
            'course_categories',
            'courses',
            'course_teachers',
            'course_sections',
            'lessons',
            'videos',
            'lesson_progress',
            'orders',
            'order_items',
            'payments',
            'enrollments',
            'coupons',
            'coupon_usages',
            'mock_tests',
            'question_groups',
            'questions',
            'test_attempts',
            'band_score_conversions',
            'writing_tasks',
            'writing_evaluations',
            'speaking_tasks',
            'speaking_evaluations',
            'certificates',
            'notifications',
            'settings',
            'activity_logs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumn('users', 'locale'));
        $this->assertTrue(Schema::hasColumn('courses', 'price'));
        $this->assertTrue(Schema::hasColumn('certificates', 'certificate_number'));
    }
}
