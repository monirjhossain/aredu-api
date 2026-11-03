<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpeakingTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part' => $this->part,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'preparation_seconds' => $this->preparation_seconds,
            'response_seconds' => $this->response_seconds,
            'is_published' => (bool) $this->is_published,
            'questions_count' => $this->whenCounted('questions'),
            'questions' => SpeakingQuestionResource::collection($this->whenLoaded('questions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
