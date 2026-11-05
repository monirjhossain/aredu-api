<?php

namespace Tests\Feature;

use App\Models\MockTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFreeMockPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoints_list_only_published_free_samples(): void
    {
        MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Free Reading Sample',
            'slug' => 'free-reading-sample',
            'instructions' => 'Try me',
            'duration_seconds' => 3600,
            'is_published' => true,
            'is_free_sample' => true,
        ]);

        MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Paid Only Reading',
            'slug' => 'paid-only-reading',
            'duration_seconds' => 3600,
            'is_published' => true,
            'is_free_sample' => false,
        ]);

        MockTest::query()->create([
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => 'general',
            'title' => 'Draft Free Listening',
            'slug' => 'draft-free-listening',
            'duration_seconds' => 2400,
            'is_published' => false,
            'is_free_sample' => true,
        ]);

        $this->getJson('/api/v1/public/reading-tests')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'free-reading-sample')
            ->assertJsonPath('data.0.is_free_sample', true);

        $this->getJson('/api/v1/public/reading-tests/free-reading-sample')
            ->assertOk()
            ->assertJsonPath('data.title', 'Free Reading Sample');

        $this->getJson('/api/v1/public/reading-tests/paid-only-reading')
            ->assertNotFound();

        $this->getJson('/api/v1/public/listening-tests')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
