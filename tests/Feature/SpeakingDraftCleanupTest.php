<?php

namespace Tests\Feature;

use App\Models\FullMockExam;
use App\Models\FullMockExamSection;
use App\Models\FullMockSession;
use App\Models\FullMockSessionSection;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\User;
use App\Services\Speaking\SpeakingDraftCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpeakingDraftCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_old_uploaded_drafts_are_deleted_but_submitted_and_fresh_are_kept(): void
    {
        $task = $this->task();
        $user = User::factory()->create();

        $oldPath = 'speaking-recordings/'.$task->id.'/'.$user->id.'/old.webm';
        Storage::disk('local')->put($oldPath, 'audio-old');
        $old = SpeakingRecording::query()->create([
            'speaking_task_id' => $task->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $oldPath,
            'mime' => 'audio/webm',
            'size_bytes' => 9,
            'status' => SpeakingRecording::STATUS_UPLOADED,
            'context_key' => SpeakingRecording::CONTEXT_PRACTICE,
        ]);
        SpeakingRecording::query()->whereKey($old->id)->update([
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $freshPath = 'speaking-recordings/'.$task->id.'/'.$user->id.'/fresh.webm';
        Storage::disk('local')->put($freshPath, 'audio-fresh');
        SpeakingRecording::query()->create([
            'speaking_task_id' => $task->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $freshPath,
            'mime' => 'audio/webm',
            'size_bytes' => 10,
            'status' => SpeakingRecording::STATUS_UPLOADED,
            'context_key' => 'fmss:1',
        ]);

        $submittedPath = 'speaking-recordings/'.$task->id.'/'.$user->id.'/submitted.webm';
        Storage::disk('local')->put($submittedPath, 'audio-submitted');
        $submitted = SpeakingRecording::query()->create([
            'speaking_task_id' => $task->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $submittedPath,
            'mime' => 'audio/webm',
            'size_bytes' => 11,
            'status' => SpeakingRecording::STATUS_SUBMITTED,
            'context_key' => SpeakingRecording::CONTEXT_PRACTICE,
            'submitted_at' => now()->subDays(5),
        ]);
        SpeakingRecording::query()->whereKey($submitted->id)->update([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $this->artisan('speaking:cleanup-drafts', ['--hours' => 48])
            ->assertSuccessful();

        $this->assertDatabaseMissing('speaking_recordings', ['id' => $old->id]);
        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertTrue(Storage::disk('local')->exists($freshPath));
        $this->assertTrue(Storage::disk('local')->exists($submittedPath));
        $this->assertDatabaseHas('speaking_recordings', ['id' => $submitted->id]);
    }

    public function test_draft_linked_to_full_mock_section_is_skipped(): void
    {
        $task = $this->task();
        $user = User::factory()->create();
        $path = 'speaking-recordings/'.$task->id.'/'.$user->id.'/linked.webm';
        Storage::disk('local')->put($path, 'audio');

        $recording = SpeakingRecording::query()->create([
            'speaking_task_id' => $task->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'mime' => 'audio/webm',
            'size_bytes' => 5,
            'status' => SpeakingRecording::STATUS_UPLOADED,
            'context_key' => 'fmss:1',
        ]);
        SpeakingRecording::query()->whereKey($recording->id)->update([
            'updated_at' => now()->subDays(4),
        ]);

        $exam = FullMockExam::query()->create([
            'exam_type' => 'academic',
            'title' => 'Cleanup Mock',
            'slug' => 'cleanup-mock-'.Str::lower(Str::random(6)),
            'is_published' => true,
            'is_free' => true,
            'allow_resume' => true,
        ]);
        $examSection = FullMockExamSection::query()->create([
            'full_mock_exam_id' => $exam->id,
            'skill' => FullMockExamSection::SKILL_SPEAKING,
            'title' => 'Speaking',
            'position' => 1,
            'duration_seconds' => 300,
            'speaking_task_id' => $task->id,
        ]);
        $session = FullMockSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'full_mock_exam_id' => $exam->id,
            'user_id' => $user->id,
            'status' => FullMockSession::STATUS_IN_PROGRESS,
            'current_position' => 1,
            'started_at' => now()->subDays(4),
        ]);
        FullMockSessionSection::query()->create([
            'full_mock_session_id' => $session->id,
            'full_mock_exam_section_id' => $examSection->id,
            'skill' => FullMockExamSection::SKILL_SPEAKING,
            'title' => 'Speaking',
            'position' => 1,
            'duration_seconds' => 300,
            'status' => FullMockSessionSection::STATUS_IN_PROGRESS,
            'speaking_recording_id' => $recording->id,
        ]);

        $result = app(SpeakingDraftCleanupService::class)->cleanup(24);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['skipped_linked']);
        $this->assertDatabaseHas('speaking_recordings', ['id' => $recording->id]);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    private function task(): SpeakingTask
    {
        return SpeakingTask::query()->create([
            'part' => SpeakingTask::PART_2,
            'title' => 'Cleanup speaking',
            'instructions' => 'Speak',
            'preparation_seconds' => 60,
            'response_seconds' => 120,
            'is_published' => true,
        ]);
    }
}
