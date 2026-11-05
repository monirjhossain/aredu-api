<?php

namespace App\Services\Landing;

use App\Http\Resources\PublicCourseResource;
use App\Models\Course;
use App\Services\Settings\SettingService;
use Illuminate\Support\Facades\Cache;

class LandingService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return Cache::remember('landing.public.v1', 60, function () {
            $branding = $this->settings->publicBranding();

            return [
                'branding' => $branding,
                'about' => (string) ($branding['about'] ?? ''),
                'videos' => $branding['videos'] ?? [],
                'courses' => $this->featuredCourses(),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function featuredCourses(): array
    {
        $courses = Course::query()
            ->with(['category:id,name'])
            ->where('is_published', true)
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'category_id', 'title', 'slug', 'description', 'thumbnail_path', 'exam_type', 'price', 'discount_price', 'duration_minutes', 'learners_count', 'class_starts_on', 'batch_number', 'seats_left', 'offer_ends_on', 'is_published']);

        return PublicCourseResource::collection($courses)->resolve();
    }
}
