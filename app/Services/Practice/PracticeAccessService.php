<?php

namespace App\Services\Practice;

use App\Models\Enrollment;
use App\Models\FullMockExam;
use App\Models\MockTest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Practice entitlement gate for student skill APIs.
 *
 * Contract (stable for Phase 0+):
 * - Reading/Listening list endpoints include meta.practice_access = { full_unlocked: bool }.
 * - full_unlocked=false → only published is_free_sample mock tests are listed/startable.
 * - Writing/Speaking require full_unlocked; otherwise list is empty and show/submit return 403.
 * - Active enrollment on any course (including free) sets full_unlocked=true.
 * - Full mock exams: is_free exams are open to everyone; otherwise full_unlocked is required.
 */
class PracticeAccessService
{
    public function hasFullPracticeAccess(User $user): bool
    {
        return Enrollment::query()
            ->where('user_id', $user->id)
            ->where('status', Enrollment::ACTIVE)
            ->exists();
    }

    /**
     * @return array{full_unlocked: bool}
     */
    public function meta(User $user): array
    {
        return [
            'full_unlocked' => $this->hasFullPracticeAccess($user),
        ];
    }

    public function assertFullPracticeAccess(User $user): void
    {
        if ($this->hasFullPracticeAccess($user)) {
            return;
        }

        throw new AuthorizationException('Enroll in a course to unlock full practice.');
    }

    public function assertCanAccessMock(User $user, MockTest $test): void
    {
        if ($test->is_free_sample) {
            return;
        }

        $this->assertFullPracticeAccess($user);
    }

    public function canAccessFullMock(User $user, FullMockExam $exam): bool
    {
        return $exam->is_free || $this->hasFullPracticeAccess($user);
    }

    public function assertCanAccessFullMock(User $user, FullMockExam $exam): void
    {
        if ($exam->is_free) {
            return;
        }

        if ($this->hasFullPracticeAccess($user)) {
            return;
        }

        throw new AuthorizationException('Enroll in a course to unlock this full mock exam.');
    }
}
