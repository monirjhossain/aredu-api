<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;

class ListeningAudioRequest extends FormRequest
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
            'audio' => ['required', 'file', 'mimes:mp3,wav,m4a,aac,ogg,mpeg', 'max:51200'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:18000'],
        ];
    }
}
