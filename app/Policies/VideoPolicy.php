<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('videos.manage');
    }

    public function update(User $user, Video $video): bool
    {
        return $user->hasPermission('videos.manage');
    }
}
