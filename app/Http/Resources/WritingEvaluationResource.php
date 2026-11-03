<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WritingEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'writing_submission_id' => $this->writing_submission_id,
            'teacher_id' => $this->teacher_id,
            'task_response' => $this->task_response,
            'coherence' => $this->coherence,
            'lexical' => $this->lexical,
            'grammar' => $this->grammar,
            'overall_band' => $this->overall_band,
            'feedback' => $this->feedback,
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
            'teacher' => $this->whenLoaded('teacher', fn () => [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
            ]),
        ];
    }
}
