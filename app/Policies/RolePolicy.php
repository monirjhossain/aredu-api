<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.manage')
            || $user->hasPermission('users.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage')
            || $user->hasPermission('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function syncPermissions(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage');
    }
}
