<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_manages_settings_and_activity_logs_are_recorded(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole('super_admin');
        $adminToken = $admin->createToken('api')->plainTextToken;

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.platform_name', 'AR Education IELTS')
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.course_access_rules.editable', false)
            ->assertJsonStructure([
                'data' => [
                    'about_text',
                    'landing_videos',
                    'payment_gateways' => [
                        'sslcommerz' => ['configured', 'sandbox'],
                        'bkash' => ['configured', 'sandbox'],
                    ],
                    'notification_preferences' => [
                        'notify_on_payment',
                        'notify_on_enrollment',
                        'notify_on_evaluation',
                        'notify_on_mock_result',
                        'notify_on_new_lesson',
                    ],
                ],
            ]);

        $this->getJson('/api/v1/settings/public')
            ->assertOk()
            ->assertJsonPath('data.platform_name', 'AR Education IELTS');

        Auth::forgetGuards();
        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/settings', [
                'platform_name' => 'AR IELTS Lab',
                'contact_information' => 'Dhaka, Bangladesh',
                'support_email' => 'support@example.com',
                'currency' => 'BDT',
                'about_text' => 'Landing about copy.',
                'landing_videos' => [
                    ['title' => 'Welcome', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                ],
                'notify_on_payment' => true,
                'notify_on_enrollment' => false,
                'notify_on_evaluation' => true,
                'notify_on_mock_result' => true,
                'notify_on_new_lesson' => true,
                'site_popup_enabled' => true,
                'site_popup_title' => 'Welcome offer',
                'site_popup_body' => 'Try free courses today.',
                'site_popup_cta_label' => 'Open free courses',
                'site_popup_cta_url' => '/free-courses',
            ])
            ->assertOk()
            ->assertJsonPath('data.platform_name', 'AR IELTS Lab')
            ->assertJsonPath('data.about_text', 'Landing about copy.')
            ->assertJsonPath('data.landing_videos.0.title', 'Welcome')
            ->assertJsonPath('data.notification_preferences.notify_on_enrollment', false)
            ->assertJsonPath('data.entry_popup.enabled', true)
            ->assertJsonPath('data.entry_popup.title', 'Welcome offer');

        $this->getJson('/api/v1/settings/public')
            ->assertOk()
            ->assertJsonPath('data.entry_popup.enabled', true)
            ->assertJsonPath('data.entry_popup.cta_url', '/free-courses');

        $this->assertSame('AR IELTS Lab', Setting::query()->where('key', 'platform_name')->value('value'));
        $this->assertTrue(
            ActivityLog::query()->where('action', 'settings.updated')->where('user_id', $admin->id)->exists()
        );

        Auth::forgetGuards();
        $logo = $this->withToken($adminToken)
            ->post('/api/v1/admin/settings/logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 120, 80),
            ], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNotEmpty($logo->json('data.logo_url'));

        $this->assertTrue(ActivityLog::query()->where('action', 'settings.logo_updated')->exists());

        Auth::forgetGuards();
        $login = $this->postJson('/api/v1/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'user.login')
                ->where('user_id', $admin->id)
                ->exists()
        );

        $token = $login->json('data.token');
        Auth::forgetGuards();
        $course = $this->makeCourse('Audit Course');
        $this->withToken($token)
            ->putJson('/api/v1/admin/courses/'.$course->id, [
                'category_id' => $course->category_id,
                'title' => 'Audit Course Updated',
                'slug' => $course->slug,
                'description' => 'Updated',
                'exam_type' => 'academic',
                'price' => 500,
                'discount_price' => null,
                'duration_minutes' => 30,
                'is_published' => true,
            ])
            ->assertOk();

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'course.updated')
                ->where('subject_id', $course->id)
                ->exists()
        );

        Auth::forgetGuards();
        $logs = $this->withToken($token)
            ->getJson('/api/v1/admin/activity-logs?action=user.login')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $logs->json('meta.total'));
        $this->assertSame('user.login', $logs->json('data.0.action'));

        Auth::forgetGuards();
        $teacher = $this->userWithRole('teacher');
        $this->withToken($teacher->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/settings')
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->userWithRole('student')->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/activity-logs')
            ->assertForbidden();
    }

    private function makeCourse(string $title): Course
    {
        $category = CourseCategory::query()->first()
            ?? CourseCategory::query()->create(['name' => 'Cat', 'slug' => 'cat-'.uniqid()]);

        return Course::query()->create([
            'category_id' => $category->id,
            'title' => $title,
            'slug' => 'course-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 500,
            'duration_minutes' => 30,
            'is_published' => true,
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
