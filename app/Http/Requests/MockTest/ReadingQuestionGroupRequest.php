<?php

namespace App\Http\Requests\MockTest;

use App\Models\QuestionGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadingQuestionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(QuestionGroup::TYPES)],
            'instructions' => ['nullable', 'string'],
            'question_passage_id' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
