<?php

namespace Tests\Feature;

use App\Models\MockTest;
use App\Models\QuestionGroup;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ReadingMockTestManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_only_mock_test_managers_can_access_builder(): void
    {
        $this->getJson('/api/v1/admin/reading-tests')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->getJson('/api/v1/admin/reading-tests')
            ->assertForbidden();

        $this->withToken($this->tokenFor('teacher'))
            ->postJson('/api/v1/admin/reading-tests', $this->testPayload())
            ->assertForbidden();
    }

    public function test_admin_can_build_reading_test_with_all_question_shapes(): void
    {
        $admin = $this->userWithRole('super_admin');
        $token = $admin->createToken('api')->plainTextToken;

        $created = $this->withToken($token)
            ->postJson('/api/v1/admin/reading-tests', $this->testPayload([
                'title' => 'City Parks Reading',
                'slug' => 'city-parks-reading',
                'exam_type' => 'academic',
                'duration_seconds' => 3600,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.module', 'reading')
            ->assertJsonPath('data.is_published', false);

        $testId = $created->json('data.id');

        $withSection = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections", [
                'title' => 'Passage 1',
            ])
            ->assertCreated();

        $sectionId = $withSection->json('data.sections.0.id');

        $withPassage = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/passages", [
                'title' => 'Urban Green Spaces',
                'body' => 'Many cities are expanding parks to improve public health. Local councils report higher visitor numbers each year.',
            ])
            ->assertCreated();

        $passageId = $withPassage->json('data.sections.0.passages.0.id');

        $mcq = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                'instructions' => 'Choose the correct letter.',
                'question_passage_id' => $passageId,
            ])
            ->assertCreated();

        $mcqGroupId = collect($mcq->json('data.sections.0.groups'))->firstWhere('type', 'multiple_choice')['id'];

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$mcqGroupId}/options", [
                'label' => 'A',
                'content' => 'Visitor numbers fell',
            ])
            ->assertCreated();

        $optionB = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$mcqGroupId}/options", [
                'label' => 'B',
                'content' => 'Visitor numbers rose',
            ])
            ->assertCreated();

        $correctId = collect($optionB->json('data.sections.0.groups'))
            ->firstWhere('id', $mcqGroupId)['options'][1]['id'];

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$mcqGroupId}/questions", [
                'prompt' => 'What happened to park visitor numbers?',
                'marks' => 1,
                'correct_option_id' => $correctId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.sections.0.groups.0.questions.0.correct_option_id', $correctId);

        $tfng = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_TRUE_FALSE_NOT_GIVEN,
                'question_passage_id' => $passageId,
            ])
            ->assertCreated();

        $tfngGroup = collect($tfng->json('data.sections.0.groups'))->firstWhere('type', 'true_false_not_given');
        $this->assertCount(3, $tfngGroup['options']);

        $trueOptionId = collect($tfngGroup['options'])->firstWhere('content', 'True')['id'];

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$tfngGroup['id']}/questions", [
                'prompt' => 'Cities are expanding parks.',
                'correct_option_id' => $trueOptionId,
            ])
            ->assertCreated();

        $ynng = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_YES_NO_NOT_GIVEN,
                'question_passage_id' => $passageId,
            ])
            ->assertCreated();
        $ynngGroup = collect($ynng->json('data.sections.0.groups'))->firstWhere('type', 'yes_no_not_given');
        $this->assertCount(3, $ynngGroup['options']);

        $matching = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_MATCHING_HEADINGS,
                'question_passage_id' => $passageId,
            ])
            ->assertCreated();
        $matchGroupId = collect($matching->json('data.sections.0.groups'))->firstWhere('type', 'matching_headings')['id'];

        $heading = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$matchGroupId}/options", [
                'label' => 'i',
                'content' => 'Public health benefits',
            ])
            ->assertCreated();
        $headingId = collect($heading->json('data.sections.0.groups'))
            ->firstWhere('id', $matchGroupId)['options'][0]['id'];

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$matchGroupId}/questions", [
                'prompt' => 'Paragraph A',
                'correct_option_id' => $headingId,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_MATCHING_INFORMATION,
                'question_passage_id' => $passageId,
            ])
            ->assertCreated();

        $completion = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_SENTENCE_COMPLETION,
                'question_passage_id' => $passageId,
            ])
            ->assertCreated();
        $completionGroupId = collect($completion->json('data.sections.0.groups'))
            ->firstWhere('type', 'sentence_completion')['id'];

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$completionGroupId}/questions", [
                'prompt' => 'Councils report higher ______ each year.',
                'accepted_answers' => ['visitor numbers', 'Visitor numbers'],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.sections.0.groups.'.(count($completion->json('data.sections.0.groups')) - 1).'.questions.0.accepted_answers');

        // Fix assertion - find sentence_completion group after create
        $afterCompletion = $this->withToken($token)
            ->getJson("/api/v1/admin/reading-tests/{$testId}")
            ->assertOk();
        $sentenceGroup = collect($afterCompletion->json('data.sections.0.groups'))
            ->firstWhere('type', 'sentence_completion');
        $this->assertCount(2, $sentenceGroup['questions'][0]['accepted_answers']);

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_SUMMARY_COMPLETION,
                'question_passage_id' => $passageId,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->putJson("/api/v1/admin/reading-tests/{$testId}/publication", ['is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$completionGroupId}/options", [
                'content' => 'Should fail',
            ])
            ->assertStatus(422);

        $other = $this->userWithRole('student');
        $this->withToken($other->createToken('api')->plainTextToken)
            ->putJson("/api/v1/admin/reading-tests/{$testId}", $this->testPayload([
                'title' => 'Hacked',
            ]))
            ->assertForbidden();

        $listening = MockTest::query()->create([
            'module' => MockTest::MODULE_LISTENING,
            'exam_type' => 'academic',
            'title' => 'Listening sample',
            'slug' => 'listening-sample',
            'duration_seconds' => 1800,
            'is_published' => false,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/reading-tests/'.$listening->id)
            ->assertNotFound();
    }

    public function test_correct_option_must_belong_to_same_group(): void
    {
        $admin = $this->userWithRole('super_admin');
        $token = $admin->createToken('api')->plainTextToken;

        $testId = $this->withToken($token)
            ->postJson('/api/v1/admin/reading-tests', $this->testPayload())
            ->json('data.id');

        $sectionId = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections", ['title' => 'S1'])
            ->json('data.sections.0.id');

        $groupA = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
            ])
            ->json('data.sections.0.groups.0.id');

        $groupBPayload = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups", [
                'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
            ])
            ->json('data.sections.0.groups');

        $groupB = collect($groupBPayload)->last()['id'];

        $optionInB = $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$groupB}/options", [
                'label' => 'A',
                'content' => 'Wrong group option',
            ])
            ->json('data.sections.0.groups');
        $optionId = collect($optionInB)->firstWhere('id', $groupB)['options'][0]['id'];

        $this->withToken($token)
            ->postJson("/api/v1/admin/reading-tests/{$testId}/sections/{$sectionId}/groups/{$groupA}/questions", [
                'prompt' => 'Q1',
                'correct_option_id' => $optionId,
            ])
            ->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function testPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Sample Reading',
            'slug' => 'sample-reading-'.uniqid(),
            'exam_type' => 'general',
            'instructions' => 'Read the passages and answer the questions.',
            'duration_seconds' => 3600,
            'is_published' => false,
        ], $overrides);
    }

    private function tokenFor(string $roleSlug): string
    {
        return $this->userWithRole($roleSlug)->createToken('api')->plainTextToken;
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
