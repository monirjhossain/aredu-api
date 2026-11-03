<?php

namespace App\Policies;

use App\Models\SpeakingTask;
use App\Models\User;

class SpeakingTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('speaking.manage');
    }

    public function view(User $user, SpeakingTask $speakingTask): bool
    {
        return $user->hasPermission('speaking.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('speaking.manage');
    }

    public function update(User $user, SpeakingTask $speakingTask): bool
    {
        return $user->hasPermission('speaking.manage');
    }

    public function delete(User $user, SpeakingTask $speakingTask): bool
    {
        return $user->hasPermission('speaking.manage');
    }
}
