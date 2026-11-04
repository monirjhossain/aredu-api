<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            AdminUserSeeder::class,
            DemoUserSeeder::class,
            DemoCourseSeeder::class,
            DemoReadingMockSeeder::class,
            DemoListeningMockSeeder::class,
            DemoWritingSeeder::class,
            DemoSpeakingSeeder::class,
            DemoResultsSeeder::class,
        ]);
    }
}
