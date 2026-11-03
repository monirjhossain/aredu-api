<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicCourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnail_path ? url('storage/'.$this->thumbnail_path) : null,
            'exam_type' => $this->exam_type,
            'price' => $this->price,
            'discount_price' => $this->discount_price,
            'duration_minutes' => $this->duration_minutes,
            'is_enrolled' => (bool) ($this->viewer_enrolled ?? false),
            'course_progress' => $this->when(
                (bool) ($this->viewer_learning ?? false),
                fn () => $this->course_progress,
            ),
            'certificate' => $this->when(
                (bool) ($this->viewer_learning ?? false),
                fn () => $this->certificate,
            ),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'teachers' => $this->whenLoaded('teachers', fn () => $this->teachers->map(fn ($teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->name,
            ])->values()),
            'sections' => $this->when(
                $this->relationLoaded('sections'),
                function () {
                    $includeProgress = (bool) ($this->viewer_learning ?? false);
                    $progressMap = is_array($this->lesson_progress) ? $this->lesson_progress : [];

                    return $this->sections->map(fn ($section) => [
                        'id' => $section->id,
                        'title' => $section->title,
                        'position' => $section->position,
                        'lessons' => $section->lessons->map(function ($lesson) use ($includeProgress, $progressMap) {
                            $row = [
                                'id' => $lesson->id,
                                'title' => $lesson->title,
                                'position' => $lesson->position,
                                'duration_seconds' => $lesson->duration_seconds,
                                'is_free_preview' => (bool) $lesson->is_free_preview,
                            ];

                            if ($includeProgress) {
                                $progress = $progressMap[(int) $lesson->id] ?? null;
                                $row['progress'] = $progress;
                                $row['is_completed'] = $progress !== null && ! empty($progress['completed_at']);
                            }

                            return $row;
                        })->values(),
                    ])->values();
                },
            ),
        ];
    }
}
