<?php

namespace App\Policies;

use App\Models\MockTest;
use App\Models\User;

class MockTestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('mock_tests.manage');
    }

    public function view(User $user, MockTest $mockTest): bool
    {
        return $user->hasPermission('mock_tests.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('mock_tests.manage');
    }

    public function update(User $user, MockTest $mockTest): bool
    {
        return $user->hasPermission('mock_tests.manage');
    }

    public function delete(User $user, MockTest $mockTest): bool
    {
        return $user->hasPermission('mock_tests.manage');
    }
}
