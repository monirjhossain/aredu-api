<?php

namespace App\Services\Catalog;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PublicCourseService
{
    /**
     * @param  array{search?: string|null, exam_type?: string|null, category_id?: int|null, free?: bool|null}  $filters
     */
    public function paginate(array $filters, ?User $viewer): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        $page = Course::query()
            ->with(['category:id,name', 'teachers:id,name'])
            ->where('is_published', true)
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
            ->when(! empty($filters['free']), function ($query) {
                // Selling price: discount_price when set, otherwise price.
                $query->whereRaw('COALESCE(discount_price, price) <= 0');
            })
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(12);

        $this->markEnrollment($page->getCollection(), $viewer);

        return $page;
    }

    /**
     * @return Collection<int, CourseCategory>
     */
    public function categories(): Collection
    {
        return CourseCategory::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'slug']);
    }

    public function findPublished(string $slug, ?User $viewer): Course
    {
        $course = Course::query()
            ->with([
                'category:id,name',
                'teachers:id,name',
                'sections' => function ($query) {
                    $query->whereHas('lessons', fn ($lessons) => $lessons->where('is_published', true));
                },
                'sections.lessons' => function ($query) {
                    $query->where('is_published', true);
                },
            ])
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if ($course === null) {
            throw (new ModelNotFoundException)->setModel(Course::class, [$slug]);
        }

        $this->markEnrollment(new Collection([$course]), $viewer);

        return $course;
    }

    public function viewer(): ?User
    {
        $user = auth('sanctum')->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  Collection<int, Course>  $courses
     */
    private function markEnrollment(Collection $courses, ?User $viewer): void
    {
        $enrolled = [];

        if ($viewer !== null && $courses->isNotEmpty()) {
            $enrolled = Enrollment::query()
                ->where('user_id', $viewer->id)
                ->where('status', 'active')
                ->whereIn('course_id', $courses->pluck('id'))
                ->pluck('course_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        foreach ($courses as $course) {
            $course->setAttribute('viewer_enrolled', in_array((int) $course->id, $enrolled, true));
        }
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
