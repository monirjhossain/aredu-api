<?php

namespace App\Services\Lesson;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonFeedback;
use App\Models\LessonReaction;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class LessonEngagementService
{
    /**
     * @return array{likes: int, dislikes: int, my_reaction: string|null, feedback_count: int}
     */
    public function summary(User $user, Lesson $lesson): array
    {
        $this->assertCanEngage($user, $lesson);

        $counts = LessonReaction::query()
            ->where('lesson_id', $lesson->id)
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $mine = LessonReaction::query()
            ->where('lesson_id', $lesson->id)
            ->where('user_id', $user->id)
            ->value('type');

        return [
            'likes' => (int) ($counts[LessonReaction::LIKE] ?? 0),
            'dislikes' => (int) ($counts[LessonReaction::DISLIKE] ?? 0),
            'my_reaction' => is_string($mine) ? $mine : null,
            'feedback_count' => LessonFeedback::query()->where('lesson_id', $lesson->id)->count(),
        ];
    }

    /**
     * @return array{likes: int, dislikes: int, my_reaction: string|null, feedback_count: int}
     */
    public function react(User $user, Lesson $lesson, string $type): array
    {
        $this->assertCanEngage($user, $lesson);

        if (! in_array($type, [LessonReaction::LIKE, LessonReaction::DISLIKE], true)) {
            throw ValidationException::withMessages([
                'type' => ['Reaction must be like or dislike.'],
            ]);
        }

        $existing = LessonReaction::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        if ($existing && $existing->type === $type) {
            $existing->delete();
        } elseif ($existing) {
            $existing->type = $type;
            $existing->save();
        } else {
            LessonReaction::query()->create([
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'type' => $type,
            ]);
        }

        return $this->summary($user, $lesson);
    }

    /**
     * @return array{feedback: array{id: int, body: string, created_at: string|null}, engagement: array{likes: int, dislikes: int, my_reaction: string|null, feedback_count: int}}
     */
    public function submitFeedback(User $user, Lesson $lesson, string $body): array
    {
        $this->assertCanEngage($user, $lesson);

        $text = trim($body);
        if ($text === '') {
            throw ValidationException::withMessages([
                'body' => ['Feedback cannot be empty.'],
            ]);
        }

        $feedback = LessonFeedback::query()->create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'body' => $text,
        ]);

        return [
            'feedback' => [
                'id' => $feedback->id,
                'body' => $feedback->body,
                'created_at' => $feedback->created_at?->toIso8601String(),
            ],
            'engagement' => $this->summary($user, $lesson),
        ];
    }

    private function assertCanEngage(User $user, Lesson $lesson): void
    {
        $lesson->loadMissing('section.course');

        if (! $lesson->is_published || $lesson->section === null || $lesson->section->course === null) {
            throw new AuthorizationException('This lesson is not available.');
        }

        if ($lesson->is_free_preview) {
            return;
        }

        $active = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $lesson->section->course_id)
            ->where('status', EnrollmentService::ACTIVE)
            ->exists();

        if (! $active) {
            throw new AuthorizationException('An active enrollment is required.');
        }
    }
}
