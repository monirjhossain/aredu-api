<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_landing_returns_branding_courses_and_videos(): void
    {
        $category = CourseCategory::query()->create([
            'name' => 'IELTS Prep',
            'slug' => 'ielts-prep',
        ]);

        Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Academic Starter',
            'slug' => 'academic-starter',
            'exam_type' => 'academic',
            'price' => 1500,
            'is_published' => true,
        ]);

        Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Draft Course',
            'slug' => 'draft-course',
            'exam_type' => 'general',
            'price' => 1000,
            'is_published' => false,
        ]);

        Setting::query()->updateOrCreate(['key' => 'platform_name'], ['value' => 'AR Education']);
        Setting::query()->updateOrCreate(['key' => 'about_text'], ['value' => 'Company about for landing.']);
        Setting::query()->updateOrCreate(['key' => 'contact_information'], ['value' => 'Dhaka, Bangladesh']);
        Setting::query()->updateOrCreate(['key' => 'support_email'], ['value' => 'support@example.com']);
        Setting::query()->updateOrCreate(
            ['key' => 'landing_videos'],
            ['value' => json_encode([
                ['title' => 'Intro', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ], JSON_UNESCAPED_UNICODE)],
        );

        $response = $this->getJson('/api/v1/landing');

        $response->assertOk()
            ->assertJsonPath('data.branding.platform_name', 'AR Education')
            ->assertJsonPath('data.about', 'Company about for landing.')
            ->assertJsonPath('data.videos.0.title', 'Intro')
            ->assertJsonPath('data.courses.0.title', 'Academic Starter');

        $titles = collect($response->json('data.courses'))->pluck('title')->all();
        $this->assertSame(['Academic Starter'], $titles);
    }
}
