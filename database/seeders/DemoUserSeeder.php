<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Password123!';

    public function run(): void
    {
        $teacherRoleId = Role::query()->where('slug', 'teacher')->value('id');
        $studentRoleId = Role::query()->where('slug', 'student')->value('id');

        if ($teacherRoleId === null || $studentRoleId === null) {
            $this->command?->warn('DemoUserSeeder skipped: roles missing. Run RoleAndPermissionSeeder first.');

            return;
        }

        $users = [
            [
                'email' => 'teacher1@areducation.test',
                'name' => 'Nadia Rahman',
                'phone' => '01711000001',
                'role_id' => $teacherRoleId,
            ],
            [
                'email' => 'teacher2@areducation.test',
                'name' => 'Karim Hossain',
                'phone' => '01711000002',
                'role_id' => $teacherRoleId,
            ],
            [
                'email' => 'student1@areducation.test',
                'name' => 'Ayesha Khan',
                'phone' => '01811000001',
                'role_id' => $studentRoleId,
            ],
            [
                'email' => 'student2@areducation.test',
                'name' => 'Rafi Ahmed',
                'phone' => '01811000002',
                'role_id' => $studentRoleId,
            ],
        ];

        foreach ($users as $row) {
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'phone' => $row['phone'],
                    'password' => self::DEMO_PASSWORD,
                    'locale' => 'en',
                ],
            );

            $user->roles()->syncWithoutDetaching([$row['role_id']]);
        }

        $this->command?->info('Demo users ready. Password for all demo accounts: '.self::DEMO_PASSWORD);
    }
}
