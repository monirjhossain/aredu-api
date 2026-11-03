<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpeakingEvaluationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'speaking_recording_id' => $this->speaking_recording_id,
            'teacher_id' => $this->teacher_id,
            'fluency' => $this->fluency,
            'lexical' => $this->lexical,
            'grammar' => $this->grammar,
            'pronunciation' => $this->pronunciation,
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
