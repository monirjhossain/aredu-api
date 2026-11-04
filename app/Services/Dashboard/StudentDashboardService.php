<?php

namespace App\Services\Dashboard;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\SpeakingRecording;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\WritingSubmission;
use App\Services\Enrollment\EnrollmentService;
use Illuminate\Support\Facades\DB;

class StudentDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $enrollments = Enrollment::query()
            ->with(['course:id,title,slug,exam_type,duration_minutes,thumbnail_path,category_id,batch_number', 'course.category:id,name'])
            ->where('user_id', $user->id)
            ->where('status', EnrollmentService::ACTIVE)
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();

        $courseIds = $enrollments->pluck('course_id')->map(fn ($id) => (int) $id)->all();
        $progressByCourse = $this->progressByCourse($user->id, $courseIds);

        return [
            'enrolled_courses' => $enrollments->map(function (Enrollment $enrollment) use ($progressByCourse) {
                $courseId = (int) $enrollment->course_id;
                $progress = $progressByCourse[$courseId] ?? [
                    'published_lessons' => 0,
                    'completed_lessons' => 0,
                    'percent' => 0,
                ];

                return [
                    'enrollment_id' => $enrollment->id,
                    'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                    'progress' => $progress,
                    'course' => $enrollment->course ? [
                        'id' => $enrollment->course->id,
                        'title' => $enrollment->course->title,
                        'slug' => $enrollment->course->slug,
                        'exam_type' => $enrollment->course->exam_type,
                        'duration_minutes' => $enrollment->course->duration_minutes,
                        'batch_number' => $enrollment->course->batch_number,
                        'thumbnail_url' => $enrollment->course->thumbnail_path
                            ? url('storage/'.$enrollment->course->thumbnail_path)
                            : null,
                        'category' => $enrollment->course->category ? [
                            'id' => $enrollment->course->category->id,
                            'name' => $enrollment->course->category->name,
                        ] : null,
                    ] : null,
                ];
            })->values()->all(),
            'recent_lessons' => $this->recentLessons($user->id, $courseIds),
            'recent_mock_results' => $this->recentMockResults($user->id),
            'pending_writing' => $this->pendingWriting($user->id),
            'pending_speaking' => $this->pendingSpeaking($user->id),
            'notifications' => $this->notifications($user),
        ];
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<int, array{published_lessons: int, completed_lessons: int, percent: int}>
     */
    private function progressByCourse(int $userId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $published = Lesson::query()
            ->join('course_sections', 'course_sections.id', '=', 'lessons.course_section_id')
            ->whereIn('course_sections.course_id', $courseIds)
            ->whereNull('course_sections.deleted_at')
            ->whereNull('lessons.deleted_at')
            ->where('lessons.is_published', true)
            ->groupBy('course_sections.course_id')
            ->select('course_sections.course_id', DB::raw('COUNT(lessons.id) as total'))
            ->pluck('total', 'course_id');

        $completed = LessonProgress::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->join('course_sections', 'course_sections.id', '=', 'lessons.course_section_id')
            ->where('lesson_progress.user_id', $userId)
            ->whereNotNull('lesson_progress.completed_at')
            ->whereIn('course_sections.course_id', $courseIds)
            ->whereNull('course_sections.deleted_at')
            ->whereNull('lessons.deleted_at')
            ->where('lessons.is_published', true)
            ->groupBy('course_sections.course_id')
            ->select('course_sections.course_id', DB::raw('COUNT(lesson_progress.id) as total'))
            ->pluck('total', 'course_id');

        $result = [];
        foreach ($courseIds as $courseId) {
            $total = (int) ($published[$courseId] ?? 0);
            $done = (int) ($completed[$courseId] ?? 0);
            $result[$courseId] = [
                'published_lessons' => $total,
                'completed_lessons' => $done,
                'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $courseIds
     * @return list<array<string, mixed>>
     */
    private function recentLessons(int $userId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return LessonProgress::query()
            ->with([
                'lesson:id,course_section_id,title,position,duration_seconds',
                'lesson.section:id,course_id,title',
                'lesson.section.course:id,title,slug',
            ])
            ->where('user_id', $userId)
            ->whereHas('lesson.section', fn ($query) => $query->whereIn('course_id', $courseIds))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(function (LessonProgress $progress) {
                $lesson = $progress->lesson;
                $course = $lesson?->section?->course;

                return [
                    'lesson_id' => $progress->lesson_id,
                    'title' => $lesson?->title,
                    'position_seconds' => $progress->position_seconds,
                    'completed_at' => $progress->completed_at?->toIso8601String(),
                    'updated_at' => $progress->updated_at?->toIso8601String(),
                    'course' => $course ? [
                        'id' => $course->id,
                        'title' => $course->title,
                        'slug' => $course->slug,
                    ] : null,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentMockResults(int $userId): array
    {
        return TestAttempt::query()
            ->with(['mockTest:id,title,slug,module,exam_type', 'result'])
            ->where('user_id', $userId)
            ->where('status', 'submitted')
            ->whereHas('result')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (TestAttempt $attempt) => [
                'attempt_id' => $attempt->id,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'mock_test' => $attempt->mockTest ? [
                    'id' => $attempt->mockTest->id,
                    'title' => $attempt->mockTest->title,
                    'slug' => $attempt->mockTest->slug,
                    'module' => $attempt->mockTest->module,
                    'exam_type' => $attempt->mockTest->exam_type,
                ] : null,
                'result' => $attempt->result ? [
                    'raw_score' => $attempt->result->raw_score,
                    'max_score' => $attempt->result->max_score,
                    'band_score' => $attempt->result->band_score,
                ] : null,
            ])
            ->all();
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function pendingWriting(int $userId): array
    {
        $query = WritingSubmission::query()
            ->where('user_id', $userId)
            ->where('status', 'submitted')
            ->whereDoesntHave('evaluation');

        $items = (clone $query)
            ->with('task:id,title')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (WritingSubmission $submission) => [
                'id' => $submission->id,
                'task_id' => $submission->writing_task_id,
                'title' => $submission->task?->title,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
            ])
            ->all();

        return ['total' => $query->count(), 'items' => $items];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function pendingSpeaking(int $userId): array
    {
        $query = SpeakingRecording::query()
            ->where('user_id', $userId)
            ->where('status', 'submitted')
            ->whereDoesntHave('evaluation');

        $items = (clone $query)
            ->with('task:id,title')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (SpeakingRecording $recording) => [
                'id' => $recording->id,
                'task_id' => $recording->speaking_task_id,
                'title' => $recording->task?->title,
                'submitted_at' => $recording->submitted_at?->toIso8601String(),
            ])
            ->all();

        return ['total' => $query->count(), 'items' => $items];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(User $user): array
    {
        return $user->notifications()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'type' => class_basename($notification->type),
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
