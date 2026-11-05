<?php

namespace App\Http\Requests\Speaking;

use Illuminate\Foundation\Http\FormRequest;

class SpeakingRecordingUploadRequest extends FormRequest
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
        return [
            'recording' => [
                'required',
                'file',
                'max:51200',
                'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/aac,video/webm',
            ],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:900'],
            'context_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
