<?php

namespace App\Http\Resources;

use App\Support\ExamContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpeakingRecordingResource extends JsonResource
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
        } elseif ($this->status === 'uploaded') {
            $evaluationStatus = 'uploaded';
        }

        $contextKey = (string) ($this->context_key ?: 'practice');

        return [
            'id' => $this->id,
            'speaking_task_id' => $this->speaking_task_id,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'duration_seconds' => $this->duration_seconds,
            'status' => $this->status,
            'context_key' => $contextKey,
            'context_label' => ExamContext::label($contextKey),
            'evaluation_status' => $evaluationStatus,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'audio_url' => url('/api/v1/speaking-recordings/'.$this->id.'/audio'),
            'task' => $this->whenLoaded('task', fn () => SpeakingTaskResource::make($this->task)->resolve()),
            'student' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'evaluation' => $this->when(
                $this->relationLoaded('evaluation') && $this->evaluation,
                fn () => SpeakingEvaluationResource::make($this->evaluation)->resolve(),
            ),
        ];
    }
}
