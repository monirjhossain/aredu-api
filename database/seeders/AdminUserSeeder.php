<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.admin_email');
        $password = config('app.admin_password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('app.admin_name') ?: 'Super Admin',
                'password' => $password,
                'locale' => 'en',
            ],
        );

        $roleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($roleId === null) {
            return;
        }

        DB::table('role_user')->updateOrInsert(
            ['role_id' => $roleId, 'user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }
}
