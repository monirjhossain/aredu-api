<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('courses.manage');
    }

    public function view(User $user, Course $course): bool
    {
        return $user->hasPermission('courses.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('courses.manage');
    }

    public function update(User $user, Course $course): bool
    {
        return $user->hasPermission('courses.manage');
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->hasPermission('courses.manage');
    }

    public function manageContent(User $user, Course $course): bool
    {
        if ($user->hasPermission('lessons.manage')) {
            return true;
        }

        if (! $user->hasPermission('lessons.manage_assigned')) {
            return false;
        }

        return $course->teachers()
            ->where('users.id', $user->id)
            ->wherePivot('can_manage_content', true)
            ->exists();
    }
}
