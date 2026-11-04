<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
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
            'thumbnail_path' => $this->thumbnail_path,
            'thumbnail_url' => $this->thumbnail_path ? url('storage/'.$this->thumbnail_path) : null,
            'exam_type' => $this->exam_type,
            'price' => $this->price,
            'discount_price' => $this->discount_price,
            'duration_minutes' => $this->duration_minutes,
            'learners_count' => $this->learners_count,
            'class_starts_on' => $this->class_starts_on?->toDateString(),
            'batch_number' => $this->batch_number,
            'seats_left' => $this->seats_left,
            'offer_ends_on' => $this->offer_ends_on?->toDateString(),
            'offer_days_left' => $this->offerDaysLeft(),
            'is_published' => $this->is_published,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ]),
            'teachers' => $this->whenLoaded('teachers', fn () => $this->teachers->map(fn ($teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'can_manage_content' => (bool) $teacher->pivot->can_manage_content,
            ])->values()),
            'teachers_count' => $this->whenCounted('teachers'),
        ];
    }

    private function offerDaysLeft(): ?int
    {
        if ($this->offer_ends_on === null) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($this->offer_ends_on->copy()->startOfDay(), false);

        return max(0, $days);
    }
}
