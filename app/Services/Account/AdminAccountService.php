<?php

namespace App\Services\Account;

use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAccountService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    /**
     * @param  array{search?: string|null, role?: string|null, status?: string|null, per_page?: int|null}  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $search = isset($filters['search']) && is_string($filters['search']) ? trim($filters['search']) : '';
        $role = isset($filters['role']) && is_string($filters['role']) ? trim($filters['role']) : '';
        $status = isset($filters['status']) && is_string($filters['status']) ? trim($filters['status']) : '';
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(5, min($perPage, 50));

        return User::query()
            ->with(['roles:id,slug,name'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            })
            ->when($role !== '', fn ($query) => $query->whereHas(
                'roles',
                fn ($roles) => $roles->where('slug', $role),
            ))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $accountId): User
    {
        $user = User::query()
            ->with(['roles:id,slug,name'])
            ->whereKey($accountId)
            ->first();

        if ($user === null) {
            throw new AuthorizationException('This account is not available.');
        }

        return $user;
    }

    /**
     * @param  array{name: string, email: string, password: string, phone?: string|null, locale?: string|null, roles: list<string>, is_active?: bool}  $data
     */
    public function create(User $actor, array $data): User
    {
        $roles = $this->resolveRoles($actor, $data['roles'] ?? [], null);

        $user = DB::transaction(function () use ($data, $roles) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'locale' => $data['locale'] ?? 'en',
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $user->roles()->sync($roles->pluck('id'));

            return $user;
        });

        $this->activity->record(
            $actor,
            'account.created',
            $user,
            'Admin created account #'.$user->id.' ('.$user->email.').',
        );

        return $user->load('roles.permissions');
    }

    /**
     * @param  array{name: string, email: string, phone?: string|null, locale?: string|null, roles: list<string>, is_active?: bool, password?: string|null}  $data
     */
    public function update(User $actor, User $target, array $data): User
    {
        $this->guardSelfDestructiveRoleChange($actor, $target, $data['roles'] ?? []);

        $roles = $this->resolveRoles($actor, $data['roles'] ?? [], $target);

        if (array_key_exists('is_active', $data) && ! (bool) $data['is_active']) {
            $this->guardDeactivate($actor, $target);
        }

        DB::transaction(function () use ($target, $data, $roles) {
            $target->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'locale' => $data['locale'] ?? $target->locale,
            ]);

            if (array_key_exists('is_active', $data)) {
                $target->is_active = (bool) $data['is_active'];
            }

            if (! empty($data['password'])) {
                $target->password = $data['password'];
            }

            $target->save();
            $target->roles()->sync($roles->pluck('id'));
        });

        $this->activity->record(
            $actor,
            'account.updated',
            $target,
            'Admin updated account #'.$target->id.'.',
        );

        return $target->refresh()->load('roles.permissions');
    }

    public function setActive(User $actor, User $target, bool $active): User
    {
        if (! $active) {
            $this->guardDeactivate($actor, $target);
        }

        $target->is_active = $active;
        $target->save();

        if (! $active) {
            $target->tokens()->delete();
        }

        $this->activity->record(
            $actor,
            $active ? 'account.activated' : 'account.deactivated',
            $target,
            'Admin '.($active ? 'activated' : 'deactivated').' account #'.$target->id.'.',
        );

        return $target->refresh()->load('roles.permissions');
    }

    public function resetPassword(User $actor, User $target, string $password): User
    {
        $target->password = $password;
        $target->save();
        $target->tokens()->delete();

        $this->activity->record(
            $actor,
            'account.password_reset',
            $target,
            'Admin reset password for account #'.$target->id.'.',
        );

        return $target->refresh()->load('roles.permissions');
    }

    /**
     * @param  list<string>  $roleSlugs
     * @return \Illuminate\Support\Collection<int, Role>
     */
    private function resolveRoles(User $actor, array $roleSlugs, ?User $target): \Illuminate\Support\Collection
    {
        $roleSlugs = array_values(array_unique(array_filter($roleSlugs)));

        if ($roleSlugs === []) {
            throw ValidationException::withMessages([
                'roles' => ['Select at least one role.'],
            ]);
        }

        $roles = Role::query()->whereIn('slug', $roleSlugs)->get();

        if ($roles->count() !== count($roleSlugs)) {
            throw ValidationException::withMessages([
                'roles' => ['One or more roles are invalid.'],
            ]);
        }

        $wantsSuperAdmin = $roles->contains(fn (Role $role) => $role->slug === 'super_admin');
        $targetIsSuperAdmin = $target?->hasRole('super_admin') ?? false;

        if (($wantsSuperAdmin || $targetIsSuperAdmin) && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        if ($targetIsSuperAdmin && ! $wantsSuperAdmin && $this->superAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'roles' => ['The last Super Admin cannot be removed.'],
            ]);
        }

        return $roles;
    }

    /**
     * @param  list<string>  $roleSlugs
     */
    private function guardSelfDestructiveRoleChange(User $actor, User $target, array $roleSlugs): void
    {
        if ($actor->id !== $target->id) {
            return;
        }

        if (! in_array('super_admin', $roleSlugs, true) && $actor->hasRole('super_admin') && $this->superAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'roles' => ['You cannot remove your own Super Admin role.'],
            ]);
        }
    }

    private function guardDeactivate(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        if ($target->hasRole('super_admin') && $this->superAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'is_active' => ['The last Super Admin cannot be deactivated.'],
            ]);
        }
    }

    private function superAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin'))
            ->count();
    }
}
