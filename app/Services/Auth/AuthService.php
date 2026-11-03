<?php

namespace App\Services\Auth;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

class AuthService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    /**
     * @param  array{name: string, email: string, password: string, phone?: string|null, locale?: string|null}  $data
     * @return array{token: string, token_type: string, user: array<string, mixed>}
     */
    public function register(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'locale' => $data['locale'] ?? 'en',
            ]);

            $roleId = DB::table('roles')->where('slug', 'student')->value('id');

            if ($roleId === null) {
                throw new RuntimeException('Student role is not seeded.');
            }

            DB::table('role_user')->insert([
                'role_id' => $roleId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $user;
        });

        return $this->tokenPayload($user);
    }

    /**
     * @return array{token: string, token_type: string, user: array<string, mixed>}
     */
    public function login(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('These credentials do not match our records.');
        }

        $this->activity->record($user, 'user.login', $user, 'User logged in.');

        return $this->tokenPayload($user);
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    /**
     * @param  array{name: string, phone?: string|null, locale: string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->fill([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'locale' => $data['locale'],
        ])->save();

        return $user->refresh()->load('roles.permissions');
    }

    public function changePassword(User $user, string $currentPassword, string $password): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = $password;
        $user->save();

        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;

        $user->tokens()->when($currentId !== null, function ($query) use ($currentId) {
            $query->where('id', '!=', $currentId);
        })->delete();
    }

    public function sendResetLink(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    /**
     * @param  array{email: string, token: string, password: string, password_confirmation: string}  $data
     */
    public function resetPassword(array $data): void
    {
        $status = Password::reset(
            [
                'email' => $data['email'],
                'token' => $data['token'],
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This password reset token is invalid.'],
            ]);
        }
    }

    /**
     * @return array{token: string, token_type: string, user: array<string, mixed>}
     */
    private function tokenPayload(User $user): array
    {
        $user->load('roles.permissions');

        return [
            'token' => $user->createToken('api')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user)->resolve(),
        ];
    }
}
