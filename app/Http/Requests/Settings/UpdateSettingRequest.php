<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform_name' => ['required', 'string', 'max:120'],
            'contact_information' => ['nullable', 'string', 'max:2000'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'currency' => ['required', 'string', Rule::in(['BDT'])],
            'about_text' => ['nullable', 'string', 'max:5000'],
            'landing_videos' => ['nullable', 'array', 'max:8'],
            'landing_videos.*.title' => ['nullable', 'string', 'max:120'],
            'landing_videos.*.url' => ['required_with:landing_videos', 'string', 'max:500', 'url'],
            'notify_on_payment' => ['required', 'boolean'],
            'notify_on_enrollment' => ['required', 'boolean'],
            'notify_on_evaluation' => ['required', 'boolean'],
            'notify_on_mock_result' => ['required', 'boolean'],
            'notify_on_new_lesson' => ['required', 'boolean'],
        ];
    }
}
