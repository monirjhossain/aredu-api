<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpeakingQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'speaking_task_id' => $this->speaking_task_id,
            'prompt' => $this->prompt,
            'cue_card' => $this->cue_card,
            'position' => $this->position,
        ];
    }
}
