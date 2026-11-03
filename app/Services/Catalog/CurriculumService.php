<?php

namespace App\Services\Catalog;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Notifications\NewCourseLessonNotification;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Settings\SettingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurriculumService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * @return array{course: array{id: int, title: string}, sections: mixed}
     */
    public function tree(Course $course): array
    {
        $sections = CourseSection::query()
            ->where('course_id', $course->id)
            ->with(['lessons.video'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
            ],
            'sections' => $sections,
        ];
    }

    public function sectionFor(Course $course, CourseSection $section): CourseSection
    {
        if ((int) $section->course_id !== (int) $course->id) {
            throw (new ModelNotFoundException)->setModel(CourseSection::class, [$section->id]);
        }

        return $section;
    }

    public function lessonFor(CourseSection $section, Lesson $lesson): Lesson
    {
        if ((int) $lesson->course_section_id !== (int) $section->id) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lesson->id]);
        }

        return $lesson;
    }

    public function createSection(Course $course, string $title): CourseSection
    {
        return DB::transaction(function () use ($course, $title) {
            $position = (int) CourseSection::query()->where('course_id', $course->id)->max('position') + 1;

            $section = CourseSection::query()->create([
                'course_id' => $course->id,
                'title' => $title,
                'position' => $position,
            ]);

            return $section->load('lessons');
        });
    }

    public function updateSection(CourseSection $section, string $title): CourseSection
    {
        $section->title = $title;
        $section->save();

        return $section->load('lessons');
    }

    public function deleteSection(Course $course, CourseSection $section): void
    {
        DB::transaction(function () use ($course, $section) {
            Lesson::query()->where('course_section_id', $section->id)->delete();
            $section->delete();
            $this->renumberSections($course);
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function reorderSections(Course $course, array $ids): void
    {
        DB::transaction(function () use ($course, $ids) {
            $current = CourseSection::query()
                ->where('course_id', $course->id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertOrder($current, $ids, 'section_ids', 'The order must include every section once.');

            foreach (array_values($ids) as $index => $id) {
                CourseSection::query()->whereKey((int) $id)->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * @param  array{title: string, duration_seconds: int, is_free_preview: bool, is_published: bool}  $data
     */
    public function createLesson(CourseSection $section, array $data): Lesson
    {
        $lesson = DB::transaction(function () use ($section, $data) {
            $position = (int) Lesson::query()->where('course_section_id', $section->id)->max('position') + 1;

            return Lesson::query()->create([
                'course_section_id' => $section->id,
                'title' => $data['title'],
                'position' => $position,
                'duration_seconds' => $data['duration_seconds'],
                'is_free_preview' => $data['is_free_preview'],
                'is_published' => $data['is_published'],
            ]);
        });

        if ($lesson->is_published) {
            $this->notifyEnrolledStudentsOfLesson($section->course_id, $lesson);
        }

        return $lesson;
    }

    /**
     * @param  array{title: string, duration_seconds: int, is_free_preview: bool, is_published: bool}  $data
     */
    public function updateLesson(Lesson $lesson, array $data): Lesson
    {
        $wasPublished = (bool) $lesson->is_published;

        $lesson->fill([
            'title' => $data['title'],
            'duration_seconds' => $data['duration_seconds'],
            'is_free_preview' => $data['is_free_preview'],
            'is_published' => $data['is_published'],
        ]);
        $lesson->save();

        if (! $wasPublished && $lesson->is_published) {
            $courseId = (int) $lesson->section()->value('course_id');
            $this->notifyEnrolledStudentsOfLesson($courseId, $lesson);
        }

        return $lesson;
    }

    private function notifyEnrolledStudentsOfLesson(int $courseId, Lesson $lesson): void
    {
        $course = Course::query()->find($courseId);
        if ($course === null) {
            return;
        }

        if (! $this->settings->notificationsEnabled('new_lesson')) {
            return;
        }

        Enrollment::query()
            ->where('course_id', $courseId)
            ->where('status', EnrollmentService::ACTIVE)
            ->orderBy('id')
            ->chunkById(100, function ($enrollments) use ($course, $lesson) {
                $users = User::query()
                    ->whereIn('id', $enrollments->pluck('user_id')->all())
                    ->get();

                foreach ($users as $user) {
                    $user->notify(new NewCourseLessonNotification($course, $lesson));
                }
            });
    }

    public function deleteLesson(CourseSection $section, Lesson $lesson): void
    {
        DB::transaction(function () use ($section, $lesson) {
            $lesson->delete();
            $this->renumberLessons($section);
        });
    }

    /**
     * @param  list<int>  $ids
     */
    public function reorderLessons(CourseSection $section, array $ids): void
    {
        DB::transaction(function () use ($section, $ids) {
            $current = Lesson::query()
                ->where('course_section_id', $section->id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertOrder($current, $ids, 'lesson_ids', 'The order must include every lesson once.');

            foreach (array_values($ids) as $index => $id) {
                Lesson::query()->whereKey((int) $id)->update(['position' => $index + 1]);
            }
        });
    }

    private function renumberSections(Course $course): void
    {
        $ids = CourseSection::query()
            ->where('course_id', $course->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $index => $id) {
            CourseSection::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    private function renumberLessons(CourseSection $section): void
    {
        $ids = Lesson::query()
            ->where('course_section_id', $section->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $index => $id) {
            Lesson::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    /**
     * @param  list<int>  $current
     * @param  list<int|string>  $ids
     */
    private function assertOrder(array $current, array $ids, string $key, string $message): void
    {
        $incoming = array_map(fn ($id) => (int) $id, array_values($ids));
        $sorted = $incoming;
        sort($sorted);
        $expected = $current;
        sort($expected);

        if (count(array_unique($incoming)) !== count($incoming) || $sorted !== $expected) {
            throw ValidationException::withMessages([
                $key => [$message],
            ]);
        }
    }
}
