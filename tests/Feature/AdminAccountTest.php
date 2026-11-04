<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_list_create_and_update_accounts(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/accounts', [
                'name' => 'New Teacher',
                'email' => 'teacher@example.com',
                'password' => 'password123',
                'phone' => '01710000000',
                'locale' => 'bn',
                'roles' => ['teacher'],
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'teacher@example.com')
            ->assertJsonPath('data.roles.0', 'teacher')
            ->assertJsonPath('data.is_active', true);

        $account = User::query()->where('email', 'teacher@example.com')->firstOrFail();

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/accounts?role=teacher')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $account->id);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->putJson('/api/v1/admin/accounts/'.$account->id, [
                'name' => 'Updated Teacher',
                'email' => 'teacher@example.com',
                'phone' => '01710000001',
                'locale' => 'en',
                'roles' => ['teacher', 'student'],
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Teacher')
            ->assertJsonPath('data.roles.1', 'student');
    }

    public function test_admin_can_deactivate_account_and_block_login(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/accounts/'.$student->id.'/active', [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $student->tokens()->count());

        $this->postJson('/api/v1/login', [
            'email' => $student->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/accounts/'.$admin->id.'/active', [
                'is_active' => false,
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_reset_password(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $student->createToken('api');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/accounts/'.$student->id.'/reset-password', [
                'password' => 'new-password-99',
            ])
            ->assertOk();

        $this->assertSame(0, $student->tokens()->count());

        $this->postJson('/api/v1/login', [
            'email' => $student->email,
            'password' => 'new-password-99',
        ])->assertOk();
    }

    public function test_student_cannot_access_accounts(): void
    {
        $student = $this->userWithRole('student');

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/accounts')
            ->assertForbidden();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'phone' => '017'.random_int(10000000, 99999999),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user;
    }
}
