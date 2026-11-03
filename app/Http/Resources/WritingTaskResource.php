<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WritingTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_type' => $this->exam_type,
            'task_type' => $this->task_type,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'prompt' => $this->prompt,
            'image_url' => $this->image_path ? url('storage/'.$this->image_path) : null,
            'recommended_minutes' => $this->recommended_minutes,
            'min_words' => $this->min_words,
            'is_published' => (bool) $this->is_published,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
