<?php

namespace App\Services\Catalog;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CourseService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    /**
     * @param  array{search?: string|null, exam_type?: string|null, category_id?: int|null, is_published?: string|null}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return Course::query()
            ->with('category:id,name')
            ->withCount('teachers')
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('slug', 'like', $search);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->when(($filters['category_id'] ?? null) !== null && $filters['category_id'] !== '', function ($query) use ($filters) {
                $query->where('category_id', $filters['category_id']);
            })
            ->when(array_key_exists('is_published', $filters) && $filters['is_published'] !== null && $filters['is_published'] !== '', function ($query) use ($filters) {
                $query->where('is_published', $filters['is_published'] === '1' || $filters['is_published'] === 1 || $filters['is_published'] === true);
            })
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * @return array{categories: mixed, teachers: mixed}
     */
    public function formOptions(): array
    {
        return [
            'categories' => CourseCategory::query()->orderBy('name')->get(['id', 'name']),
            'teachers' => User::query()
                ->whereHas('roles', fn ($query) => $query->where('slug', 'teacher'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ];
    }

    public function find(Course $course): Course
    {
        return Course::query()->with(['category:id,name', 'teachers:id,name,email'])->findOrFail($course->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Course
    {
        $course = Course::query()->create($data);

        $this->activity->record(
            Auth::user(),
            'course.created',
            $course,
            'Course created: '.$course->title,
        );

        $this->bumpPublicCache();

        return $this->find($course);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Course $course, array $data): Course
    {
        $course->fill($data)->save();

        $this->activity->record(
            Auth::user(),
            'course.updated',
            $course,
            'Course updated: '.$course->title,
        );

        $this->bumpPublicCache();

        return $this->find($course);
    }

    public function publish(Course $course, bool $published): Course
    {
        $course->is_published = $published;
        $course->save();

        $this->activity->record(
            Auth::user(),
            $published ? 'course.published' : 'course.unpublished',
            $course,
            ($published ? 'Course published: ' : 'Course unpublished: ').$course->title,
        );

        $this->bumpPublicCache();

        return $this->find($course);
    }

    /**
     * @param  list<array{user_id: int, can_manage_content: bool}>  $teachers
     */
    public function syncTeachers(Course $course, array $teachers): Course
    {
        $ids = collect($teachers)->pluck('user_id')->all();
        $teacherIds = User::query()
            ->whereIn('id', $ids)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'teacher'))
            ->pluck('id');

        if ($teacherIds->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'teachers' => ['Only a Teacher can be assigned to a course.'],
            ]);
        }

        $sync = [];
        foreach ($teachers as $teacher) {
            $sync[$teacher['user_id']] = [
                'can_manage_content' => (bool) $teacher['can_manage_content'],
            ];
        }

        $course->teachers()->sync($sync);

        return $this->find($course);
    }

    public function storeThumbnail(Course $course, UploadedFile $file): Course
    {
        $path = $file->store('course-thumbnails', 'public');
        $previous = $course->thumbnail_path;
        $course->thumbnail_path = $path;
        $course->save();

        if (is_string($previous) && $previous !== '') {
            Storage::disk('public')->delete($previous);
        }

        return $this->find($course);
    }

    public function deleteThumbnail(Course $course): Course
    {
        if (is_string($course->thumbnail_path) && $course->thumbnail_path !== '') {
            Storage::disk('public')->delete($course->thumbnail_path);
        }

        $course->thumbnail_path = null;
        $course->save();

        return $this->find($course);
    }

    public function delete(Course $course): void
    {
        DB::transaction(function () use ($course) {
            $sectionIds = CourseSection::query()->where('course_id', $course->id)->pluck('id');

            if ($sectionIds->isNotEmpty()) {
                Lesson::query()->whereIn('course_section_id', $sectionIds)->delete();
                CourseSection::query()->whereIn('id', $sectionIds)->delete();
            }

            $course->delete();
        });

        $this->bumpPublicCache();
    }

    private function bumpPublicCache(): void
    {
        Cache::forget('landing.public.v1');
    }

    private function term(?string $search): ?string
    {
        $search = trim((string) $search);

        if ($search === '') {
            return null;
        }

        return '%'.addcslashes($search, '%_\\').'%';
    }
}
