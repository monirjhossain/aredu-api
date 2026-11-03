<?php

namespace App\Services\Catalog;

use App\Models\CourseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CourseCategoryService
{
    public function paginate(?string $search): LengthAwarePaginator
    {
        return $this->countedQuery()
            ->when($this->term($search) !== null, function ($query) use ($search) {
                $term = $this->term($search);
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', $term)->orWhere('slug', 'like', $term);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * @param  array{name: string, slug: string}  $data
     */
    public function create(array $data): CourseCategory
    {
        $category = CourseCategory::query()->create($data);

        return $this->countedQuery()->findOrFail($category->id);
    }

    public function find(CourseCategory $category): CourseCategory
    {
        return $this->countedQuery()->findOrFail($category->id);
    }

    /**
     * @param  array{name: string, slug: string}  $data
     */
    public function update(CourseCategory $category, array $data): CourseCategory
    {
        $category->fill($data)->save();

        return $this->countedQuery()->findOrFail($category->id);
    }

    public function delete(CourseCategory $category): void
    {
        if ($category->courses()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['This category is still used by a course.'],
            ]);
        }

        $category->delete();
    }

    private function countedQuery()
    {
        return CourseCategory::query()->withCount([
            'courses',
            'courses as attached_courses_count' => fn ($query) => $query->withTrashed(),
        ]);
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
