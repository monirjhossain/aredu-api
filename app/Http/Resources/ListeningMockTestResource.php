<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListeningMockTestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'exam_type' => $this->exam_type,
            'title' => $this->title,
            'slug' => $this->slug,
            'instructions' => $this->instructions,
            'duration_seconds' => $this->duration_seconds,
            'is_published' => (bool) $this->is_published,
            'is_free_sample' => (bool) $this->is_free_sample,
            'sections_count' => $this->when(isset($this->sections_count), $this->sections_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'title' => $section->title,
                'position' => $section->position,
                'audio' => $section->relationLoaded('audio') && $section->audio ? [
                    'id' => $section->audio->id,
                    'original_name' => $section->audio->original_name,
                    'duration_seconds' => $section->audio->duration_seconds,
                    'url' => url('/api/v1/admin/listening-tests/'.$this->id.'/sections/'.$section->id.'/audio'),
                ] : null,
                'groups' => $section->relationLoaded('groups')
                    ? $section->groups->map(fn ($group) => [
                        'id' => $group->id,
                        'type' => $group->type,
                        'instructions' => $group->instructions,
                        'position' => $group->position,
                        'question_passage_id' => $group->question_passage_id,
                        'options' => $group->relationLoaded('options')
                            ? $group->options->map(fn ($option) => [
                                'id' => $option->id,
                                'label' => $option->label,
                                'content' => $option->content,
                                'position' => $option->position,
                            ])->values()
                            : [],
                        'questions' => $group->relationLoaded('questions')
                            ? $group->questions->map(fn ($question) => [
                                'id' => $question->id,
                                'prompt' => $question->prompt,
                                'position' => $question->position,
                                'marks' => $question->marks,
                                'correct_option_id' => $question->correct_option_id,
                                'accepted_answers' => $question->relationLoaded('acceptedAnswers')
                                    ? $question->acceptedAnswers->map(fn ($answer) => [
                                        'id' => $answer->id,
                                        'answer_text' => $answer->answer_text,
                                    ])->values()
                                    : [],
                            ])->values()
                            : [],
                    ])->values()
                    : [],
            ])->values()),
        ];
    }
}
