<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_guest_and_student_cannot_manage_roles(): void
    {
        $this->getJson('/api/v1/admin/roles')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->getJson('/api/v1/admin/roles')
            ->assertForbidden();

        $this->withToken($this->tokenFor('teacher'))
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    public function test_student_cannot_grant_super_admin_by_changing_the_user_id(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->withToken($this->tokenFor('student'))
            ->putJson('/api/v1/admin/users/'.$admin->id.'/roles', [
                'roles' => ['super_admin'],
            ])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }

    public function test_super_admin_can_create_a_role_and_assign_it(): void
    {
        $token = $this->tokenFor('super_admin');
        $student = $this->userWithRole('student');

        $this->withToken($token)->postJson('/api/v1/admin/roles', [
            'name' => 'Editor',
            'slug' => 'editor',
        ])->assertCreated()->assertJsonPath('data.slug', 'editor');

        $this->withToken($token)->putJson('/api/v1/admin/roles/'.$this->roleId('editor').'/permissions', [
            'permissions' => ['courses.manage'],
        ])->assertOk()->assertJsonPath('data.permissions.0', 'courses.manage');

        $this->withToken($token)->putJson('/api/v1/admin/users/'.$student->id.'/roles', [
            'roles' => ['editor'],
        ])->assertOk()->assertJsonPath('data.roles.0', 'editor');

        $this->assertFalse($student->fresh()->hasRole('student'));
        $this->assertTrue($student->fresh()->hasPermission('courses.manage'));
    }

    public function test_user_manager_cannot_assign_super_admin(): void
    {
        $manager = $this->userWithPermission('users.manage');
        $student = $this->userWithRole('student');

        $this->withToken($manager->createToken('api')->plainTextToken)
            ->putJson('/api/v1/admin/users/'.$student->id.'/roles', [
                'roles' => ['super_admin'],
            ])
            ->assertForbidden();

        $this->assertFalse($student->fresh()->hasRole('super_admin'));
    }

    public function test_system_role_and_last_super_admin_are_protected(): void
    {
        $token = $this->tokenFor('super_admin');
        $admin = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin'))->firstOrFail();

        $this->withToken($token)
            ->deleteJson('/api/v1/admin/roles/'.$this->roleId('student'))
            ->assertStatus(422);

        $this->withToken($token)
            ->putJson('/api/v1/admin/users/'.$admin->id.'/roles', [
                'roles' => ['student'],
            ])
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }

    public function test_current_user_payload_includes_roles(): void
    {
        $token = $this->tokenFor('teacher');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'teacher')
            ->assertJsonFragment(['writing.evaluate']);
    }

    private function tokenFor(string $roleSlug): string
    {
        return $this->userWithRole($roleSlug)->createToken('api')->plainTextToken;
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();

        DB::table('role_user')->insert([
            'role_id' => $this->roleId($roleSlug),
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function userWithPermission(string $permissionSlug): User
    {
        $role = Role::query()->create([
            'name' => 'Limited manager',
            'slug' => 'limited-manager',
        ]);
        $role->permissions()->attach(
            Permission::query()->where('slug', $permissionSlug)->value('id'),
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function roleId(string $slug): int
    {
        return (int) DB::table('roles')->where('slug', $slug)->value('id');
    }
}
