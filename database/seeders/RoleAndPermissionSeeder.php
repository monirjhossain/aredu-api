<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $roles = [
            'super_admin' => 'Super Admin',
            'teacher' => 'Teacher',
            'student' => 'Student',
        ];

        foreach ($roles as $slug => $name) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $permissions = [
            'users.manage' => 'Manage users',
            'roles.manage' => 'Manage roles and permissions',
            'teachers.manage' => 'Manage teachers',
            'categories.manage' => 'Manage categories',
            'courses.manage' => 'Manage courses',
            'lessons.manage' => 'Manage lessons',
            'videos.manage' => 'Manage videos',
            'payments.manage' => 'Manage payments',
            'enrollments.manage' => 'Manage enrollments',
            'mock_tests.manage' => 'Manage mock tests',
            'writing.manage' => 'Manage writing tasks',
            'speaking.manage' => 'Manage speaking tasks',
            'writing.evaluate' => 'Evaluate writing',
            'speaking.evaluate' => 'Evaluate speaking',
            'reports.view' => 'View reports',
            'settings.manage' => 'Manage settings',
            'courses.view_assigned' => 'View assigned courses',
            'lessons.manage_assigned' => 'Manage assigned lesson content',
            'progress.view_assigned' => 'View assigned student progress',
        ];

        foreach ($permissions as $slug => $name) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $this->grant('super_admin', array_keys($permissions));
        $this->grant('teacher', [
            'courses.view_assigned',
            'lessons.manage_assigned',
            'writing.evaluate',
            'speaking.evaluate',
            'progress.view_assigned',
        ]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function grant(string $roleSlug, array $permissionSlugs): void
    {
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
        $now = now();

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', $permissionSlugs)
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert(
                ['permission_id' => $permissionId, 'role_id' => $roleId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
