<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WritingSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasEvaluation = $this->relationLoaded('evaluation') && $this->evaluation !== null;

        $evaluationStatus = 'draft';
        if ($this->status === 'submitted') {
            $evaluationStatus = $hasEvaluation ? 'evaluated' : 'pending';
        }

        return [
            'id' => $this->id,
            'writing_task_id' => $this->writing_task_id,
            'body' => $this->body,
            'word_count' => $this->word_count,
            'status' => $this->status,
            'evaluation_status' => $evaluationStatus,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'task' => $this->whenLoaded('task', fn () => WritingTaskResource::make($this->task)->resolve()),
            'student' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'evaluation' => $this->when(
                $this->relationLoaded('evaluation') && $this->evaluation,
                fn () => WritingEvaluationResource::make($this->evaluation)->resolve(),
            ),
        ];
    }
}
