<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_student_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Ayesha Rahman',
            'email' => 'ayesha@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'locale' => 'bn',
            'role' => 'super_admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'ayesha@example.com')
            ->assertJsonPath('data.user.locale', 'bn')
            ->assertJsonMissingPath('data.user.password');

        $userId = $response->json('data.user.id');
        $studentRoleId = DB::table('roles')->where('slug', 'student')->value('id');
        $adminRoleId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        $this->assertDatabaseHas('role_user', [
            'user_id' => $userId,
            'role_id' => $studentRoleId,
        ]);
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $userId,
            'role_id' => $adminRoleId,
        ]);

        $this->assertTrue(Hash::check(
            'password123',
            User::query()->findOrFail($userId)->password,
        ));
    }

    public function test_login_returns_a_token_and_logout_revokes_it(): void
    {
        $user = User::factory()->create();

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $login->assertOk()->assertJsonPath('success', true);
        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', [
                'email' => $user->email,
                'password' => 'not-the-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(429);
    }

    public function test_profile_and_password_can_be_updated(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/profile', [
            'name' => 'New Name',
            'phone' => '01700000000',
            'locale' => 'bn',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $this->withToken($token)->putJson('/api/v1/password', [
            'current_password' => 'wrong-password',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertStatus(422);

        $this->withToken($token)->putJson('/api/v1/password', [
            'current_password' => 'password',
            'password' => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertOk();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'newpassword1',
        ])->assertOk();
    }

    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()->assertJsonPath('success', true);

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_from_a_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'resetpassword1',
            'password_confirmation' => 'resetpassword1',
        ])->assertOk();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'resetpassword1',
        ])->assertOk();
    }
}
