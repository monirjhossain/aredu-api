<?php

namespace Database\Seeders;

use App\Models\FullMockExam;
use App\Models\FullMockExamSection;
use App\Models\MockTest;
use App\Models\SpeakingTask;
use App\Models\WritingTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent Academic full mock linking existing published demo practice content.
 * Safe to re-run: updateOrCreate by slug; sections are replaced in-place for that exam only.
 */
class DemoFullMockExamSeeder extends Seeder
{
    public const SLUG = 'demo-academic-full-mock';

    public const PAID_SLUG = 'demo-academic-full-mock-enrolled';

    public function run(): void
    {
        $this->call([
            DemoReadingMockSeeder::class,
            DemoListeningMockSeeder::class,
            FreeMockSampleSeeder::class,
            DemoWritingSeeder::class,
            DemoSpeakingSeeder::class,
        ]);

        $freeListening = MockTest::query()
            ->where('module', MockTest::MODULE_LISTENING)
            ->where('is_published', true)
            ->where('is_free_sample', true)
            ->orderBy('id')
            ->first();

        $paidListening = MockTest::query()
            ->where('module', MockTest::MODULE_LISTENING)
            ->where('is_published', true)
            ->where('is_free_sample', false)
            ->orderBy('id')
            ->first();

        $freeReading = MockTest::query()
            ->where('module', MockTest::MODULE_READING)
            ->where('exam_type', 'academic')
            ->where('is_published', true)
            ->where('is_free_sample', true)
            ->orderBy('id')
            ->first();

        $paidReading = MockTest::query()
            ->where('module', MockTest::MODULE_READING)
            ->where('exam_type', 'academic')
            ->where('is_published', true)
            ->where('is_free_sample', false)
            ->orderBy('id')
            ->first();

        $writing = WritingTask::query()
            ->where('exam_type', 'academic')
            ->where('task_type', WritingTask::TYPE_TASK_2)
            ->where('is_published', true)
            ->orderBy('id')
            ->first();

        $writingPaid = WritingTask::query()
            ->where('exam_type', 'academic')
            ->where('task_type', WritingTask::TYPE_TASK_2)
            ->where('is_published', true)
            ->where('id', '!=', $writing?->id)
            ->orderBy('id')
            ->first() ?? $writing;

        $speaking = SpeakingTask::query()
            ->where('part', SpeakingTask::PART_2)
            ->where('is_published', true)
            ->orderBy('id')
            ->first();

        $speakingPaid = SpeakingTask::query()
            ->where('part', SpeakingTask::PART_2)
            ->where('is_published', true)
            ->where('id', '!=', $speaking?->id)
            ->orderBy('id')
            ->first() ?? $speaking;

        if ($freeListening === null || $freeReading === null || $writing === null || $speaking === null) {
            $this->command?->warn('DemoFullMockExamSeeder skipped: missing published L/R/W/S content.');

            return;
        }

        $this->seedExam(
            slug: self::SLUG,
            title: 'Demo Academic Full IELTS Mock (Free)',
            isFree: true,
            listening: $freeListening,
            reading: $freeReading,
            writing: $writing,
            speaking: $speaking,
        );

        if ($paidListening !== null && $paidReading !== null && $writingPaid !== null && $speakingPaid !== null) {
            $this->seedExam(
                slug: self::PAID_SLUG,
                title: 'Demo Academic Full IELTS Mock (Enrolled)',
                isFree: false,
                listening: $paidListening,
                reading: $paidReading,
                writing: $writingPaid,
                speaking: $speakingPaid,
            );
        } else {
            $this->command?->warn('Paid full mock skipped: need enrollment-only R/L content.');
        }
    }

    private function seedExam(
        string $slug,
        string $title,
        bool $isFree,
        MockTest $listening,
        MockTest $reading,
        WritingTask $writing,
        SpeakingTask $speaking,
    ): void {
        DB::transaction(function () use ($slug, $title, $isFree, $listening, $reading, $writing, $speaking) {
            $exam = FullMockExam::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'exam_type' => FullMockExam::TYPE_ACADEMIC,
                    'title' => $title,
                    'instructions' => "This demo full mock links existing published practice content.\n"
                        ."Complete Listening → Reading → Writing → Speaking in order.\n"
                        .'You may resume if interrupted. Writing and Speaking scores require teacher evaluation.',
                    'is_free' => $isFree,
                    'allow_resume' => true,
                    'is_published' => false,
                ],
            );

            $desired = [
                [
                    'skill' => FullMockExamSection::SKILL_LISTENING,
                    'title' => 'Listening',
                    'position' => 1,
                    'duration_seconds' => min(2400, max(600, (int) $listening->duration_seconds)),
                    'mock_test_id' => $listening->id,
                    'writing_task_id' => null,
                    'speaking_task_id' => null,
                    'instructions' => 'Use the existing listening audio and questions.',
                ],
                [
                    'skill' => FullMockExamSection::SKILL_READING,
                    'title' => 'Reading',
                    'position' => 2,
                    'duration_seconds' => min(3600, max(600, (int) $reading->duration_seconds)),
                    'mock_test_id' => $reading->id,
                    'writing_task_id' => null,
                    'speaking_task_id' => null,
                    'instructions' => 'Academic reading passages from the published demo pack.',
                ],
                [
                    'skill' => FullMockExamSection::SKILL_WRITING,
                    'title' => 'Writing Task 2',
                    'position' => 3,
                    'duration_seconds' => 40 * 60,
                    'mock_test_id' => null,
                    'writing_task_id' => $writing->id,
                    'speaking_task_id' => null,
                    'instructions' => 'Write at least the recommended word count. Teacher evaluation unlocks the writing band.',
                ],
                [
                    'skill' => FullMockExamSection::SKILL_SPEAKING,
                    'title' => 'Speaking Part 2',
                    'position' => 4,
                    'duration_seconds' => max(180, (int) $speaking->preparation_seconds + (int) $speaking->response_seconds + 60),
                    'mock_test_id' => null,
                    'writing_task_id' => null,
                    'speaking_task_id' => $speaking->id,
                    'instructions' => 'Record your Part 2 response. Teacher evaluation unlocks the speaking band.',
                ],
            ];

            $keptIds = [];
            foreach ($desired as $row) {
                $section = FullMockExamSection::query()->updateOrCreate(
                    [
                        'full_mock_exam_id' => $exam->id,
                        'position' => $row['position'],
                    ],
                    $row,
                );
                $keptIds[] = $section->id;
            }

            FullMockExamSection::query()
                ->where('full_mock_exam_id', $exam->id)
                ->whereNotIn('id', $keptIds)
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('full_mock_session_sections')
                        ->whereColumn(
                            'full_mock_session_sections.full_mock_exam_section_id',
                            'full_mock_exam_sections.id',
                        );
                })
                ->delete();

            $exam->is_published = true;
            $exam->save();
        });

        $label = $isFree ? 'free' : 'enrollment-only';
        $this->command?->info("Demo full mock ready ({$label}): /full-mocks/{$slug}");
        $this->command?->line(' - Listening #'.$listening->id.' '.$listening->slug);
        $this->command?->line(' - Reading #'.$reading->id.' '.$reading->slug);
        $this->command?->line(' - Writing #'.$writing->id.' '.$writing->title);
        $this->command?->line(' - Speaking #'.$speaking->id.' '.$speaking->title);
    }
}
