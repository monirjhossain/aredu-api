<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WritingTask;

class WritingTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('writing.manage');
    }

    public function view(User $user, WritingTask $writingTask): bool
    {
        return $user->hasPermission('writing.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('writing.manage');
    }

    public function update(User $user, WritingTask $writingTask): bool
    {
        return $user->hasPermission('writing.manage');
    }

    public function delete(User $user, WritingTask $writingTask): bool
    {
        return $user->hasPermission('writing.manage');
    }
}
