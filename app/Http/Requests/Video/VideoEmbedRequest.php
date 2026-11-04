<?php

namespace App\Http\Requests\Video;

use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;

class VideoEmbedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Video::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }
};
