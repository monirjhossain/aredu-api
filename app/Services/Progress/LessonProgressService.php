<?php

namespace App\Services\Progress;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LessonProgressService
{
    public const MIN_WRITE_INTERVAL_SECONDS = 15;

    public const MIN_POSITION_DELTA_SECONDS = 10;

    public function __construct(private readonly EnrollmentService $enrollments) {}

    /**
     * @return array{progress: array<string, mixed>, saved: bool, course_progress: array<string, mixed>, certificate: array<string, mixed>|null}
     */
    public function updatePosition(User $user, Lesson $lesson, int $positionSeconds): array
    {
        $this->assertCanTrack($user, $lesson);

        if ($positionSeconds < 0) {
            throw ValidationException::withMessages([
                'position_seconds' => ['Position must be zero or greater.'],
            ]);
        }

        $progress = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        if ($progress === null) {
            $progress = LessonProgress::query()->create([
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'position_seconds' => $positionSeconds,
            ]);

            return $this->payload($user, $lesson, $progress, true);
        }

        if (! $this->shouldPersistPosition($progress, $positionSeconds)) {
            return $this->payload($user, $lesson, $progress, false);
        }

        $progress->position_seconds = max((int) $progress->position_seconds, $positionSeconds);
        $progress->save();

        return $this->payload($user, $lesson, $progress->fresh(), true);
    }

    /**
     * @return array{progress: array<string, mixed>, saved: bool, course_progress: array<string, mixed>, certificate: array<string, mixed>|null}
     */
    public function markCompleted(User $user, Lesson $lesson): array
    {
        $this->assertCanTrack($user, $lesson);

        $progress = DB::transaction(function () use ($user, $lesson) {
            $progress = LessonProgress::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                $progress = LessonProgress::query()->create([
                    'user_id' => $user->id,
                    'lesson_id' => $lesson->id,
                    'position_seconds' => 0,
                    'completed_at' => now(),
                ]);
            } elseif ($progress->completed_at === null) {
                $progress->completed_at = now();
                $progress->save();
            }

            $this->issueCertificateIfComplete($user, (int) $lesson->section->course_id);

            return $progress->fresh();
        });

        return $this->payload($user, $lesson, $progress, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forLesson(User $user, Lesson $lesson): ?array
    {
        $progress = LessonProgress::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        return $progress ? $this->serializeProgress($progress) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mapForCourse(User $user, Course $course): array
    {
        $lessonIds = $course->sections
            ->flatMap(fn ($section) => $section->lessons->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($lessonIds === []) {
            return [];
        }

        return LessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', $lessonIds)
            ->get()
            ->mapWithKeys(fn (LessonProgress $progress) => [
                (int) $progress->lesson_id => $this->serializeProgress($progress),
            ])
            ->all();
    }

    /**
     * @return array{published_lessons: int, completed_lessons: int, percent: int}
     */
    public function courseProgress(User $user, int $courseId): array
    {
        $published = Lesson::query()
            ->join('course_sections', 'course_sections.id', '=', 'lessons.course_section_id')
            ->where('course_sections.course_id', $courseId)
            ->whereNull('course_sections.deleted_at')
            ->whereNull('lessons.deleted_at')
            ->where('lessons.is_published', true)
            ->count();

        $completed = LessonProgress::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->join('course_sections', 'course_sections.id', '=', 'lessons.course_section_id')
            ->where('lesson_progress.user_id', $user->id)
            ->whereNotNull('lesson_progress.completed_at')
            ->where('course_sections.course_id', $courseId)
            ->whereNull('course_sections.deleted_at')
            ->whereNull('lessons.deleted_at')
            ->where('lessons.is_published', true)
            ->count();

        return [
            'published_lessons' => $published,
            'completed_lessons' => $completed,
            'percent' => $published > 0 ? (int) round(($completed / $published) * 100) : 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function certificateFor(User $user, int $courseId): ?array
    {
        $certificate = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        return $certificate ? $this->serializeCertificate($certificate) : null;
    }

    private function assertCanTrack(User $user, Lesson $lesson): void
    {
        $lesson->loadMissing('section.course');

        if (! $lesson->is_published || $lesson->section === null || $lesson->section->course === null) {
            throw new AuthorizationException('This lesson is not available.');
        }

        if ($lesson->section->course->trashed() || ! $lesson->section->course->is_published) {
            throw new AuthorizationException('This lesson is not available.');
        }

        if (! $this->enrollments->hasActiveAccess($user->id, (int) $lesson->section->course_id)) {
            throw new AuthorizationException('An active enrollment is required to track lesson progress.');
        }
    }

    private function shouldPersistPosition(LessonProgress $progress, int $positionSeconds): bool
    {
        $current = (int) $progress->position_seconds;

        if ($positionSeconds <= $current) {
            return false;
        }

        if (($positionSeconds - $current) >= self::MIN_POSITION_DELTA_SECONDS) {
            return true;
        }

        $updatedAt = $progress->updated_at;

        if ($updatedAt === null) {
            return true;
        }

        return $updatedAt->lte(now()->subSeconds(self::MIN_WRITE_INTERVAL_SECONDS));
    }

    private function issueCertificateIfComplete(User $user, int $courseId): void
    {
        $stats = $this->courseProgress($user, $courseId);

        if ($stats['published_lessons'] === 0 || $stats['completed_lessons'] < $stats['published_lessons']) {
            return;
        }

        $exists = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            return;
        }

        Certificate::query()->create([
            'user_id' => $user->id,
            'course_id' => $courseId,
            'certificate_number' => $this->uniqueCertificateNumber(),
            'issued_at' => now(),
        ]);
    }

    private function uniqueCertificateNumber(): string
    {
        do {
            $number = 'AR'.strtoupper(Str::random(14));
        } while (Certificate::query()->where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * @return array{progress: array<string, mixed>, saved: bool, course_progress: array<string, mixed>, certificate: array<string, mixed>|null}
     */
    private function payload(User $user, Lesson $lesson, LessonProgress $progress, bool $saved): array
    {
        $courseId = (int) $lesson->section->course_id;

        return [
            'progress' => $this->serializeProgress($progress),
            'saved' => $saved,
            'course_progress' => $this->courseProgress($user, $courseId),
            'certificate' => $this->certificateFor($user, $courseId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProgress(LessonProgress $progress): array
    {
        return [
            'lesson_id' => (int) $progress->lesson_id,
            'position_seconds' => (int) $progress->position_seconds,
            'completed_at' => $progress->completed_at?->toIso8601String(),
            'updated_at' => $progress->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCertificate(Certificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'certificate_number' => $certificate->certificate_number,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
        ];
    }
}
