<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_section_id' => $this->course_section_id,
            'title' => $this->title,
            'position' => $this->position,
            'duration_seconds' => $this->duration_seconds,
            'is_free_preview' => $this->is_free_preview,
            'is_published' => $this->is_published,
            'video' => $this->whenLoaded('video', fn () => $this->video ? [
                'id' => $this->video->id,
                'provider' => $this->video->provider,
                'external_id' => $this->video->external_id,
                'embed_url' => $this->video->embed_url,
                'source_url' => $this->video->source_url,
                'status' => $this->video->status,
                'duration_seconds' => $this->video->duration_seconds,
                'error_message' => $this->video->error_message,
                'cover_url' => $this->video->cover_path ? url('storage/'.$this->video->cover_path) : null,
            ] : null),
        ];
    }
}
