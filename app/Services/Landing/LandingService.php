<?php

namespace App\Services\Landing;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\Settings\SettingService;

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
        $branding = $this->settings->publicBranding();

        return [
            'branding' => $branding,
            'about' => (string) $this->settings->get(SettingService::KEY_ABOUT_TEXT, ''),
            'videos' => $this->settings->landingVideos(),
            'courses' => $this->featuredCourses(),
            'preview_lessons' => $this->previewLessons(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function featuredCourses(): array
    {
        return Course::query()
            ->with(['category:id,name'])
            ->where('is_published', true)
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'exam_type' => $course->exam_type,
                'price' => $course->price,
                'discount_price' => $course->discount_price,
                'duration_minutes' => $course->duration_minutes,
                'thumbnail_url' => $course->thumbnail_path
                    ? url('storage/'.$course->thumbnail_path)
                    : null,
                'category' => $course->category ? [
                    'id' => $course->category->id,
                    'name' => $course->category->name,
                ] : null,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previewLessons(): array
    {
        return Lesson::query()
            ->where('is_free_preview', true)
            ->where('is_published', true)
            ->whereHas('section.course', function ($query) {
                $query->where('is_published', true);
            })
            ->with([
                'section.course:id,title,slug,thumbnail_path',
                'video:id,lesson_id,status,cover_path',
            ])
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(function (Lesson $lesson) {
                $course = $lesson->section?->course;
                $cover = $lesson->video?->cover_path;

                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'duration_seconds' => $lesson->duration_seconds,
                    'course_title' => $course?->title,
                    'course_slug' => $course?->slug,
                    'cover_url' => $cover
                        ? url('storage/'.ltrim($cover, '/'))
                        : ($course?->thumbnail_path ? url('storage/'.$course->thumbnail_path) : null),
                    'video_ready' => $lesson->video?->status === 'ready',
                ];
            })
            ->all();
    }
}
