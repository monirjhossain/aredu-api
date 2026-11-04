<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'enrolled_at' => optional($this->enrolled_at)?->toIso8601String(),
            'order_id' => $this->order_id,
            'progress' => $this->when(isset($this->progress_stats), $this->progress_stats),
            'course' => $this->whenLoaded('course', fn () => $this->course ? [
                'id' => $this->course->id,
                'title' => $this->course->title,
                'slug' => $this->course->slug,
                'exam_type' => $this->course->exam_type,
                'duration_minutes' => $this->course->duration_minutes,
                'batch_number' => $this->course->batch_number,
                'thumbnail_url' => $this->course->thumbnail_path
                    ? url('storage/'.$this->course->thumbnail_path)
                    : null,
                'category' => $this->course->relationLoaded('category') && $this->course->category ? [
                    'id' => $this->course->category->id,
                    'name' => $this->course->category->name,
                ] : null,
            ] : null),
        ];
    }
}
