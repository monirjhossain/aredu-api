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

/**
 * Seeds IELTS-style Listening tests: 4 sections, ~40 questions, full band table.
 * Audio files are tiny valid silent MP3 placeholders — replace with real audio in admin.
 */
class DemoListeningMockSeeder extends Seeder
{
    /**
     * Tiny valid silent MP3 (plays in browsers; replace with real recordings before go-live).
     */
    private const SILENT_MP3_BASE64 = '/+MYxAAAAANIAAAAAExBTUUzLjk4LjIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function run(): void
    {
        $this->seedBandTable('academic');
        $this->seedBandTable('general');

        $tests = [
            [
                'slug' => 'listening-demo-campus-tour-1',
                'title' => 'IELTS Listening Practice — Campus & City Life',
                'exam_type' => 'academic',
                'pack' => 'campus',
            ],
            [
                'slug' => 'listening-demo-library-orientation-2',
                'title' => 'IELTS Listening Practice — Library & Community',
                'exam_type' => 'general',
                'pack' => 'library',
            ],
            [
                'slug' => 'listening-ielts-travel-services',
                'title' => 'IELTS Listening Practice — Travel Services',
                'exam_type' => 'academic',
                'pack' => 'travel',
            ],
            [
                'slug' => 'listening-ielts-university-seminar',
                'title' => 'IELTS Listening Practice — University Seminar',
                'exam_type' => 'academic',
                'pack' => 'seminar',
            ],
            [
                'slug' => 'listening-ielts-housing-health',
                'title' => 'IELTS Listening Practice — Housing & Health',
                'exam_type' => 'general',
                'pack' => 'housing',
            ],
            [
                'slug' => 'listening-ielts-environment-lecture',
                'title' => 'IELTS Listening Practice — Environment Lecture',
                'exam_type' => 'academic',
                'pack' => 'environment',
            ],
        ];

        $keepSlugs = array_column($tests, 'slug');

        foreach ($tests as $test) {
            $this->seedFullTest($test);
        }

        // Soft-delete older thin demo listening tests from previous seeders.
        $removed = MockTest::query()
            ->where('module', MockTest::MODULE_LISTENING)
            ->where('slug', 'like', 'listening-demo-%')
            ->whereNotIn('slug', $keepSlugs)
            ->delete();

        $this->command?->info('IELTS-style listening mocks ready: '.count($tests).' tests × 40 questions (+ full 0–40 band table)');
        if ($removed) {
            $this->command?->line("Soft-deleted {$removed} legacy thin listening demos.");
        }
    }

    /**
     * @param  array{slug: string, title: string, exam_type: string, pack: string}  $data
     */
    private function seedFullTest(array $data): void
    {
        $test = MockTest::query()->updateOrCreate(
            ['slug' => $data['slug']],
            [
                'module' => MockTest::MODULE_LISTENING,
                'exam_type' => $data['exam_type'],
                'title' => $data['title'],
                'instructions' => "You will hear four recordings.\n"
                    ."Answer all questions as you listen. Each recording is played once in the real test.\n"
                    .'There are 40 questions in total. Transfer answers carefully.',
                'duration_seconds' => 2400,
                'is_published' => true,
            ],
        );

        $blueprint = $this->blueprint($data['pack']);
        $globalPosition = 1;

        foreach ($blueprint as $sectionIndex => $sectionData) {
            $sectionNumber = $sectionIndex + 1;
            $path = "listening-audio/{$test->id}/section-{$sectionNumber}.mp3";
            Storage::disk('local')->put($path, base64_decode(self::SILENT_MP3_BASE64) ?: 'ID3');

            $audio = QuestionAudio::query()->updateOrCreate(
                [
                    'disk' => 'local',
                    'path' => $path,
                ],
                [
                    'duration_seconds' => $sectionData['duration'],
                    'original_name' => "section-{$sectionNumber}.mp3",
                ],
            );

            $section = MockTestSection::query()->updateOrCreate(
                [
                    'mock_test_id' => $test->id,
                    'position' => $sectionNumber,
                ],
                [
                    'title' => $sectionData['title'],
                    'question_audio_id' => $audio->id,
                ],
            );

            // Remove old groups so re-seed stays clean at 40 Q.
            $oldGroupIds = QuestionGroup::query()
                ->where('mock_test_section_id', $section->id)
                ->pluck('id');
            if ($oldGroupIds->isNotEmpty()) {
                $questionIds = Question::query()->whereIn('question_group_id', $oldGroupIds)->pluck('id');
                QuestionAcceptedAnswer::query()->whereIn('question_id', $questionIds)->delete();
                Question::query()->whereIn('question_group_id', $oldGroupIds)->delete();
                QuestionOption::query()->whereIn('question_group_id', $oldGroupIds)->delete();
                QuestionGroup::query()->whereIn('id', $oldGroupIds)->delete();
            }

            foreach ($sectionData['groups'] as $groupIndex => $groupData) {
                $group = QuestionGroup::query()->create([
                    'mock_test_section_id' => $section->id,
                    'position' => $groupIndex + 1,
                    'type' => $groupData['type'],
                    'instructions' => $groupData['instructions'],
                ]);

                $optionMap = [];
                foreach ($groupData['options'] ?? [] as $optIndex => $option) {
                    $row = QuestionOption::query()->create([
                        'question_group_id' => $group->id,
                        'label' => $option['label'],
                        'content' => $option['content'],
                        'position' => $optIndex + 1,
                    ]);
                    $optionMap[$option['label']] = $row->id;
                }

                foreach ($groupData['questions'] as $qIndex => $questionData) {
                    $question = Question::query()->create([
                        'question_group_id' => $group->id,
                        'position' => $qIndex + 1,
                        'prompt' => 'Q'.$globalPosition.'. '.$questionData['prompt'],
                        'marks' => 1,
                        'correct_option_id' => isset($questionData['correct'])
                            ? ($optionMap[$questionData['correct']] ?? null)
                            : null,
                    ]);

                    foreach ($questionData['answers'] ?? [] as $answer) {
                        QuestionAcceptedAnswer::query()->create([
                            'question_id' => $question->id,
                            'answer_text' => $answer,
                        ]);
                    }

                    $globalPosition++;
                }
            }
        }
    }

    /**
     * @return list<array{title: string, duration: int, groups: list<array<string, mixed>>}>
     */
    private function blueprint(string $pack): array
    {
        return match ($pack) {
            'library' => $this->packLibrary(),
            'travel' => $this->packTravel(),
            'seminar' => $this->packSeminar(),
            'housing' => $this->packHousing(),
            'environment' => $this->packEnvironment(),
            default => $this->packCampus(),
        };
    }

    /** @return list<array{title: string, duration: int, groups: list<array<string, mixed>>}> */
    private function packCampus(): array
    {
        return [
            [
                'title' => 'Section 1 — Campus housing enquiry',
                'duration' => 420,
                'groups' => [
                    [
                        'type' => QuestionGroup::TYPE_SENTENCE_COMPLETION,
                        'instructions' => 'Complete the notes below. Write NO MORE THAN TWO WORDS AND/OR A NUMBER for each answer.',
                        'questions' => [
                            ['prompt' => 'Student name: Anna ______', 'answers' => ['bennett', 'Bennett']],
                            ['prompt' => 'Student ID: ______', 'answers' => ['482913', '482 913']],
                            ['prompt' => 'Preferred room type: ______ room', 'answers' => ['single', 'Single']],
                            ['prompt' => 'Arrival date: ______ July', 'answers' => ['12', '12th', 'twelfth']],
                            ['prompt' => 'Contact number: 07______', 'answers' => ['745 2291', '7452291', '745-2291']],
                        ],
                    ],
                    [
                        'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                        'instructions' => 'Choose the correct letter, A, B or C.',
                        'options' => [
                            ['label' => 'A', 'content' => 'Near the sports centre'],
                            ['label' => 'B', 'content' => 'Beside the library'],
                            ['label' => 'C', 'content' => 'Opposite the canteen'],
                        ],
                        'questions' => [
                            ['prompt' => 'Where is West Hall located?', 'correct' => 'B'],
                            ['prompt' => 'What must students bring on arrival day?', 'correct' => 'A'],
                            ['prompt' => 'When can laundry facilities be used?', 'correct' => 'C'],
                            ['prompt' => 'Who should students email about maintenance?', 'correct' => 'A'],
                            ['prompt' => 'What is included in the rent?', 'correct' => 'B'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Section 2 — City museum welcome talk',
                'duration' => 450,
                'groups' => [
                    [
                        'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                        'instructions' => 'Choose the correct letter, A, B or C.',
                        'options' => [
                            ['label' => 'A', 'content' => 'Free for students'],
                            ['label' => 'B', 'content' => 'Half price after 4 pm'],
                            ['label' => 'C', 'content' => 'Included with city pass'],
                        ],
                        'questions' => [
                            ['prompt' => 'Museum entry for university students is', 'correct' => 'A'],
                            ['prompt' => 'The guided tour starts at', 'correct' => 'C'],
                            ['prompt' => 'Photography is allowed in', 'correct' => 'B'],
                            ['prompt' => 'The cafe closes at', 'correct' => 'A'],
                        ],
                    ],
                    [
                        'type' => QuestionGroup::TYPE_MATCHING_INFORMATION,
                        'instructions' => 'Which facility matches each description? Choose the correct letter A–F. You may use any letter more than once.',
                        'options' => [
                            ['label' => 'A', 'content' => 'Ticket desk'],
                            ['label' => 'B', 'content' => 'Gift shop'],
                            ['label' => 'C', 'content' => 'Lecture theatre'],
                            ['label' => 'D', 'content' => 'Temporary gallery'],
                            ['label' => 'E', 'content' => 'Family zone'],
                            ['label' => 'F', 'content' => 'Roof garden'],
                        ],
                        'questions' => [
                            ['prompt' => 'Where visitors collect audio guides', 'correct' => 'A'],
                            ['prompt' => 'Where weekend workshops are held', 'correct' => 'C'],
                            ['prompt' => 'Where children’s activities run', 'correct' => 'E'],
                            ['prompt' => 'Where postcards are sold', 'correct' => 'B'],
                            ['prompt' => 'Where the outdoor sculpture display is', 'correct' => 'F'],
                            ['prompt' => 'Where the photography exhibition is this month', 'correct' => 'D'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Section 3 — Tutorial discussion (group project)',
                'duration' => 480,
                'groups' => [
                    [
                        'type' => QuestionGroup::TYPE_MATCHING_INFORMATION,
                        'instructions' => 'What task will each student do? Choose the correct letter A–E.',
                        'options' => [
                            ['label' => 'A', 'content' => 'Design the survey'],
                            ['label' => 'B', 'content' => 'Analyse the data'],
                            ['label' => 'C', 'content' => 'Write the introduction'],
                            ['label' => 'D', 'content' => 'Prepare the slides'],
                            ['label' => 'E', 'content' => 'Contact local businesses'],
                        ],
                        'questions' => [
                            ['prompt' => 'Maya will', 'correct' => 'A'],
                            ['prompt' => 'Tom will', 'correct' => 'E'],
                            ['prompt' => 'Sara will', 'correct' => 'B'],
                            ['prompt' => 'Leo will', 'correct' => 'D'],
                            ['prompt' => 'Nina will', 'correct' => 'C'],
                        ],
                    ],
                    [
                        'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                        'instructions' => 'Choose the correct letter, A, B or C.',
                        'options' => [
                            ['label' => 'A', 'content' => 'Next Monday'],
                            ['label' => 'B', 'content' => 'Next Wednesday'],
                            ['label' => 'C', 'content' => 'Next Friday'],
                        ],
                        'questions' => [
                            ['prompt' => 'The first draft is due on', 'correct' => 'B'],
                            ['prompt' => 'They will meet the tutor on', 'correct' => 'A'],
                            ['prompt' => 'The presentation is scheduled for', 'correct' => 'C'],
                            ['prompt' => 'Library group rooms must be booked by', 'correct' => 'B'],
                            ['prompt' => 'Peer feedback should be finished by', 'correct' => 'A'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Section 4 — Lecture: Urban heat islands',
                'duration' => 540,
                'groups' => [
                    [
                        'type' => QuestionGroup::TYPE_SUMMARY_COMPLETION,
                        'instructions' => 'Complete the summary. Write NO MORE THAN TWO WORDS AND/OR A NUMBER for each answer.',
                        'questions' => [
                            ['prompt' => 'Cities can be several degrees ______ than surrounding countryside.', 'answers' => ['warmer', 'hotter']],
                            ['prompt' => 'Dark surfaces such as ______ absorb more heat.', 'answers' => ['asphalt', 'roads', 'tarmac']],
                            ['prompt' => 'Planting ______ on roofs can reduce temperatures.', 'answers' => ['vegetation', 'plants', 'gardens', 'green roofs']],
                            ['prompt' => 'Researchers measured temperatures using ______ sensors.', 'answers' => ['remote', 'satellite', 'rooftop']],
                            ['prompt' => 'The largest temperature gap occurs at ______.', 'answers' => ['night', 'night-time', 'nighttime']],
                            ['prompt' => 'Poor neighbourhoods often have fewer ______.', 'answers' => ['trees', 'parks', 'green spaces']],
                            ['prompt' => 'Cool pavements reflect more ______.', 'answers' => ['sunlight', 'solar radiation', 'heat']],
                            ['prompt' => 'One health risk linked to heat islands is ______.', 'answers' => ['dehydration', 'heatstroke', 'heat stroke']],
                            ['prompt' => 'City planners recommend increasing ______ cover.', 'answers' => ['tree', 'canopy', 'vegetation']],
                            ['prompt' => 'The lecturer’s case study focused on ______.', 'answers' => ['melbourne', 'Melbourne', 'singapore', 'Singapore']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return list<array{title: string, duration: int, groups: list<array<string, mixed>>}> */
    private function packLibrary(): array
    {
        $base = $this->packCampus();
        $base[0]['title'] = 'Section 1 — Library membership call';
        $base[0]['groups'][0]['questions'] = [
            ['prompt' => 'Caller’s surname: ______', 'answers' => ['collins', 'Collins']],
            ['prompt' => 'Membership type: ______', 'answers' => ['student', 'Student']],
            ['prompt' => 'Expiry date: ______ March', 'answers' => ['30', '30th', 'thirtieth']],
            ['prompt' => 'Books currently borrowed: ______', 'answers' => ['3', 'three']],
            ['prompt' => 'Fine to pay: £______', 'answers' => ['2.50', '2.5', '2,50']],
        ];
        $base[1]['title'] = 'Section 2 — Community centre open day';
        $base[2]['title'] = 'Section 3 — Study skills workshop planning';
        $base[3]['title'] = 'Section 4 — Lecture: History of public libraries';
        $base[3]['groups'][0]['questions'][9] = [
            'prompt' => 'The lecturer’s case study focused on ______.',
            'answers' => ['manchester', 'Manchester', 'birmingham', 'Birmingham'],
        ];

        return $base;
    }

    /** @return list<array{title: string, duration: int, groups: list<array<string, mixed>>}> */
    private function packTravel(): array
    {
        $base = $this->packCampus();
        $base[0]['title'] = 'Section 1 — Travel agency booking';
        $base[0]['groups'][0]['questions'] = [
            ['prompt' => 'Destination: ______', 'answers' => ['lisbon', 'Lisbon']],
            ['prompt' => 'Departure date: ______ August', 'answers' => ['18', '18th']],
            ['prompt' => 'Number of travellers: ______', 'answers' => ['2', 'two']],
            ['prompt' => 'Hotel name: Hotel ______', 'answers' => ['aurora', 'Aurora']],
            ['prompt' => 'Booking reference: ______', 'answers' => ['tr5491', 'TR5491', 'tr 5491']],
        ];
        $base[1]['title'] = 'Section 2 — Airport facilities talk';
        $base[2]['title'] = 'Section 3 — Tourism project meeting';
        $base[3]['title'] = 'Section 4 — Lecture: Sustainable tourism';

        return $base;
    }

    /** @return list<array{title: string, duration: int, groups: list<array<string, mixed>>}> */
    private function packSeminar(): array
    {
        $base = $this->packCampus();
        $base[0]['title'] = 'Section 1 — Course registration helpline';
        $base[1]['title'] = 'Section 2 — Careers fair introduction';
        $base[2]['title'] = 'Section 3 — Research methods seminar';
        $base[3]['title'] = 'Section 4 — Lecture: Memory and learning';

        return $base;
    }

    /** @return list<array{title: string, duration: int, groups: list<array<string, mixed>>}> */
    private function packHousing(): array
    {
        $base = $this->packLibrary();
        $base[0]['title'] = 'Section 1 — Flat rental enquiry';
        $base[1]['title'] = 'Section 2 — Local health clinic tour';
        $base[2]['title'] = 'Section 3 — Neighbourhood survey discussion';
        $base[3]['title'] = 'Section 4 — Lecture: Affordable housing policy';

        return $base;
    }

    /** @return list<array{title: string, duration: int, groups: list<array<string, mixed>>}> */
    private function packEnvironment(): array
    {
        $base = $this->packCampus();
        $base[0]['title'] = 'Section 1 — Recycling centre booking';
        $base[1]['title'] = 'Section 2 — Nature park visitor talk';
        $base[2]['title'] = 'Section 3 — Fieldwork planning meeting';
        $base[3]['title'] = 'Section 4 — Lecture: Plastic pollution in oceans';

        return $base;
    }

    private function seedBandTable(string $examType): void
    {
        // Approximate Cambridge IELTS Listening conversion (Academic/General share listening scale).
        $rows = [
            40 => 9.0, 39 => 9.0,
            38 => 8.5, 37 => 8.5,
            36 => 8.0, 35 => 8.0,
            34 => 7.5, 33 => 7.5, 32 => 7.5,
            31 => 7.0, 30 => 7.0,
            29 => 6.5, 28 => 6.5, 27 => 6.5, 26 => 6.5,
            25 => 6.0, 24 => 6.0, 23 => 6.0,
            22 => 5.5, 21 => 5.5, 20 => 5.5, 19 => 5.5, 18 => 5.5,
            17 => 5.0, 16 => 5.0,
            15 => 4.5, 14 => 4.5, 13 => 4.5,
            12 => 4.0, 11 => 4.0,
            10 => 3.5, 9 => 3.5, 8 => 3.5,
            7 => 3.0, 6 => 3.0,
            5 => 2.5, 4 => 2.5,
            3 => 2.0, 2 => 2.0,
            1 => 1.0,
            0 => 0.0,
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
