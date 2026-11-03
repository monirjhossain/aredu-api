<?php

namespace App\Policies;

use App\Models\CourseCategory;
use App\Models\User;

class CourseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('categories.manage');
    }

    public function view(User $user, CourseCategory $category): bool
    {
        return $user->hasPermission('categories.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('categories.manage');
    }

    public function update(User $user, CourseCategory $category): bool
    {
        return $user->hasPermission('categories.manage');
    }

    public function delete(User $user, CourseCategory $category): bool
    {
        return $user->hasPermission('categories.manage');
    }
}
