<?php

namespace App\Services\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    public function roles(): Collection
    {
        return Role::query()->with('permissions')->orderBy('name')->get();
    }

    /**
     * @param  array{name: string, slug: string}  $data
     */
    public function createRole(array $data): Role
    {
        return Role::query()->create($data)->load('permissions');
    }

    /**
     * @param  array{name: string, slug: string}  $data
     */
    public function updateRole(Role $role, array $data): Role
    {
        if ($role->isSystem() && $data['slug'] !== $role->slug) {
            throw ValidationException::withMessages([
                'slug' => ['A system role slug cannot be changed.'],
            ]);
        }

        $role->fill($data)->save();

        return $role->load('permissions');
    }

    public function deleteRole(Role $role): void
    {
        if ($role->isSystem()) {
            throw ValidationException::withMessages([
                'role' => ['This role cannot be deleted.'],
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => ['This role is still assigned to users.'],
            ]);
        }

        $role->permissions()->detach();
        $role->delete();
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    public function syncRolePermissions(Role $role, array $permissionSlugs): Role
    {
        $permissionSlugs = array_values(array_unique($permissionSlugs));
        $permissions = Permission::query()->whereIn('slug', $permissionSlugs)->get();

        if ($permissions->count() !== count($permissionSlugs)) {
            throw ValidationException::withMessages([
                'permissions' => ['One or more permissions are invalid.'],
            ]);
        }

        if ($role->slug === 'super_admin' && ! in_array('roles.manage', $permissionSlugs, true)) {
            throw ValidationException::withMessages([
                'permissions' => ['Super Admin must keep the roles.manage permission.'],
            ]);
        }

        $role->permissions()->sync($permissions->pluck('id'));

        $this->activity->record(
            auth()->user(),
            'role.permissions_updated',
            $role,
            'Permissions updated for role '.$role->slug.'.',
        );

        return $role->load('permissions');
    }

    public function permissions(): Collection
    {
        return Permission::query()->orderBy('slug')->get();
    }

    /**
     * @param  array{name: string, slug: string}  $data
     */
    public function createPermission(array $data): Permission
    {
        return Permission::query()->create($data);
    }

    public function deletePermission(Permission $permission): void
    {
        if ($permission->roles()->exists()) {
            throw ValidationException::withMessages([
                'permission' => ['This permission is still assigned to a role.'],
            ]);
        }

        $permission->delete();
    }

    public function users(): LengthAwarePaginator
    {
        return User::query()->with('roles.permissions')->orderBy('name')->paginate(15);
    }

    /**
     * @param  list<string>  $roleSlugs
     */
    public function syncUserRoles(User $actor, User $target, array $roleSlugs): User
    {
        $roles = Role::query()->whereIn('slug', $roleSlugs)->get();

        if ($roles->count() !== count(array_unique($roleSlugs))) {
            throw ValidationException::withMessages([
                'roles' => ['One or more roles are invalid.'],
            ]);
        }

        $wantsSuperAdmin = $roles->contains(fn (Role $role) => $role->slug === 'super_admin');
        $targetIsSuperAdmin = $target->hasRole('super_admin');

        if (($wantsSuperAdmin || $targetIsSuperAdmin) && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        if ($targetIsSuperAdmin && ! $wantsSuperAdmin && $this->superAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'roles' => ['The last Super Admin cannot be removed.'],
            ]);
        }

        DB::transaction(function () use ($target, $roles) {
            $target->roles()->sync($roles->pluck('id'));
        });

        $this->activity->record(
            $actor,
            'user.roles_updated',
            $target,
            'Roles updated for user #'.$target->id.': '.$roles->pluck('slug')->implode(', '),
        );

        return $target->load('roles.permissions');
    }

    private function superAdminCount(): int
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('slug', 'super_admin'))
            ->count();
    }
}
