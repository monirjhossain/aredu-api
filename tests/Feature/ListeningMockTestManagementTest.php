<?php

namespace Tests\Feature;

use App\Models\MockTest;
use App\Models\QuestionGroup;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListeningMockTestManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_only_managers_can_access_listening_builder(): void
    {
        $this->getJson('/api/v1/admin/listening-tests')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->getJson('/api/v1/admin/listening-tests')
            ->assertForbidden();
    }

    public function test_admin_creates_four_sections_uploads_audio_and_builds_questions(): void
    {
        $token = $this->tokenFor('super_admin');

        $created = $this->withToken($token)
            ->postJson('/api/v1/admin/listening-tests', [
                'title' => 'Campus Listening',
                'slug' => 'campus-listening',
                'exam_type' => 'academic',
                'duration_seconds' => 2400,
                'instructions' => 'Listen and answer.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.module', 'listening')
            ->assertJsonCount(4, 'data.sections');

        $testId = $created->json('data.id');
        $sections = $created->json('data.sections');

        $this->withToken($token)
            ->putJson("/api/v1/admin/listening-tests/{$testId}/publication", ['is_published' => true])
            ->assertStatus(422);

        foreach ($sections as $section) {
            $this->withToken($token)
                ->post("/api/v1/admin/listening-tests/{$testId}/sections/{$section['id']}/audio", [
                    'audio' => UploadedFile::fake()->create('part-'.$section['position'].'.mp3', 200, 'audio/mpeg'),
                    'duration_seconds' => 300,
                ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('data.sections.'.($section['position'] - 1).'.audio.original_name', 'part-'.$section['position'].'.mp3');
        }

        $sectionId = $sections[0]['id'];

        $group = $this->withToken($token)
            ->postJson("/api/v1/admin/listening-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                'instructions' => 'Choose the correct letter.',
            ])
            ->assertCreated();

        $groupId = $group->json('data.sections.0.groups.0.id');

        $option = $this->withToken($token)
            ->postJson("/api/v1/admin/listening-tests/{$testId}/sections/{$sectionId}/groups/{$groupId}/options", [
                'label' => 'A',
                'content' => 'Library',
            ])
            ->assertCreated();
        $optionId = $option->json('data.sections.0.groups.0.options.0.id');

        $this->withToken($token)
            ->postJson("/api/v1/admin/listening-tests/{$testId}/sections/{$sectionId}/groups/{$groupId}/questions", [
                'prompt' => 'Where is the meeting?',
                'marks' => 1,
                'correct_option_id' => $optionId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sections.0.groups.0.questions.0.correct_option_id', $optionId);

        $this->withToken($token)
            ->postJson("/api/v1/admin/listening-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_SENTENCE_COMPLETION,
            ])
            ->assertCreated();

        $completionGroupId = collect(
            $this->withToken($token)->getJson("/api/v1/admin/listening-tests/{$testId}")->json('data.sections.0.groups')
        )->firstWhere('type', 'sentence_completion')['id'];

        $this->withToken($token)
            ->postJson("/api/v1/admin/listening-tests/{$testId}/sections/{$sectionId}/groups/{$completionGroupId}/questions", [
                'prompt' => 'The office opens at ______.',
                'accepted_answers' => ['9 am', '09:00'],
            ])
            ->assertCreated();

        $this->withToken($token)
            ->putJson("/api/v1/admin/listening-tests/{$testId}/publication", ['is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        $audio = $this->withToken($token)
            ->get("/api/v1/admin/listening-tests/{$testId}/sections/{$sectionId}/audio");
        $audio->assertOk();

        $this->withToken($this->tokenFor('student'))
            ->get("/api/v1/admin/listening-tests/{$testId}/sections/{$sectionId}/audio")
            ->assertForbidden();

        $reading = MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Reading only',
            'slug' => 'reading-only-x',
            'duration_seconds' => 3600,
            'is_published' => false,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/listening-tests/'.$reading->id)
            ->assertNotFound();
    }

    private function tokenFor(string $roleSlug): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user->createToken('api')->plainTextToken;
    }
}
