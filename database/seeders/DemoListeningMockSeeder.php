<?php

namespace Database\Seeders;

use App\Models\BandScoreConversion;
use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionAcceptedAnswer;
use App\Models\QuestionAudio;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoListeningMockSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBandTable('academic');
        $this->seedBandTable('general');

        $topics = [
            'Campus Tour', 'Library Orientation', 'Museum Visit', 'Travel Booking', 'Hotel Enquiry',
            'University Lecture', 'Science Seminar', 'Job Interview', 'Shopping Centre', 'Airport Check-in',
            'Sports Club', 'Housing Office', 'Medical Centre', 'Theatre Booking', 'Bank Appointment',
            'Weather Report', 'City Map Talk', 'Course Advice', 'Volunteer Briefing', 'Conference Intro',
        ];

        foreach ($topics as $index => $topic) {
            $examType = $index % 2 === 0 ? 'academic' : 'general';
            $n = $index + 1;
            $this->seedTest([
                'slug' => 'listening-demo-'.Str::slug($topic).'-'.$n,
                'title' => sprintf('%s Listening %02d', $topic, $n),
                'exam_type' => $examType,
                'instructions' => 'You will hear four sections. Answer all questions as you listen. You may hear each recording once.',
                'topic' => $topic,
            ]);
        }

        $this->command?->info('Demo listening mocks ready: 20 published tests at /listening-tests');
    }

    /**
     * @param  array{slug: string, title: string, exam_type: string, instructions: string, topic: string}  $data
     */
    private function seedTest(array $data): void
    {
        $test = MockTest::query()->updateOrCreate(
            ['slug' => $data['slug']],
            [
                'module' => MockTest::MODULE_LISTENING,
                'exam_type' => $data['exam_type'],
                'title' => $data['title'],
                'instructions' => $data['instructions'],
                'duration_seconds' => 2400,
                'is_published' => true,
            ],
        );

        for ($i = 1; $i <= 4; $i++) {
            $path = "listening-audio/{$test->id}/section-{$i}.mp3";
            Storage::disk('local')->put($path, "demo-listening-audio-{$test->id}-{$i}");

            $audio = QuestionAudio::query()->updateOrCreate(
                [
                    'disk' => 'local',
                    'path' => $path,
                ],
                [
                    'duration_seconds' => 90 + ($i * 10),
                    'original_name' => "section-{$i}.mp3",
                ],
            );

            $section = MockTestSection::query()->updateOrCreate(
                [
                    'mock_test_id' => $test->id,
                    'position' => $i,
                ],
                [
                    'title' => 'Section '.$i,
                    'question_audio_id' => $audio->id,
                ],
            );

            $mcqGroup = QuestionGroup::query()->updateOrCreate(
                [
                    'mock_test_section_id' => $section->id,
                    'position' => 1,
                ],
                [
                    'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                    'instructions' => 'Choose the correct letter, A, B or C.',
                ],
            );

            $optionA = QuestionOption::query()->updateOrCreate(
                ['question_group_id' => $mcqGroup->id, 'label' => 'A'],
                ['content' => $data['topic'].' desk', 'position' => 1],
            );
            $optionB = QuestionOption::query()->updateOrCreate(
                ['question_group_id' => $mcqGroup->id, 'label' => 'B'],
                ['content' => $data['topic'].' hall', 'position' => 2],
            );
            QuestionOption::query()->updateOrCreate(
                ['question_group_id' => $mcqGroup->id, 'label' => 'C'],
                ['content' => $data['topic'].' office', 'position' => 3],
            );

            Question::query()->updateOrCreate(
                [
                    'question_group_id' => $mcqGroup->id,
                    'position' => 1,
                ],
                [
                    'prompt' => "Section {$i}: Where does the speaker ask people to meet for {$data['topic']}?",
                    'marks' => 1,
                    'correct_option_id' => $optionB->id,
                ],
            );

            $completionGroup = QuestionGroup::query()->updateOrCreate(
                [
                    'mock_test_section_id' => $section->id,
                    'position' => 2,
                ],
                [
                    'type' => QuestionGroup::TYPE_SENTENCE_COMPLETION,
                    'instructions' => 'Complete the sentence with NO MORE THAN TWO WORDS.',
                ],
            );

            $completion = Question::query()->updateOrCreate(
                [
                    'question_group_id' => $completionGroup->id,
                    'position' => 1,
                ],
                [
                    'prompt' => "Section {$i}: Write the missing word about {$data['topic']}: Meet at the ______.",
                    'marks' => 1,
                    'correct_option_id' => null,
                ],
            );

            QuestionAcceptedAnswer::query()->updateOrCreate(
                [
                    'question_id' => $completion->id,
                    'answer_text' => 'hall',
                ],
                [],
            );

            unset($optionA);
        }
    }

    private function seedBandTable(string $examType): void
    {
        $rows = [
            8 => 9.0,
            7 => 8.5,
            6 => 7.5,
            5 => 6.5,
            4 => 5.5,
            3 => 4.5,
            2 => 3.5,
            1 => 2.5,
            0 => 1.0,
        ];

        foreach ($rows as $raw => $band) {
            BandScoreConversion::query()->updateOrCreate(
                [
                    'module' => 'listening',
                    'exam_type' => $examType,
                    'raw_score' => $raw,
                ],
                ['band_score' => $band],
            );
        }
    }
}
