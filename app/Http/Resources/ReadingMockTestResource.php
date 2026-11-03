<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReadingMockTestResource extends JsonResource
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
            'sections_count' => $this->when(isset($this->sections_count), $this->sections_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'title' => $section->title,
                'position' => $section->position,
                'passages' => $section->relationLoaded('passages')
                    ? $section->passages->map(fn ($passage) => [
                        'id' => $passage->id,
                        'title' => $passage->title,
                        'body' => $passage->body,
                        'position' => $passage->position,
                    ])->values()
                    : [],
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
