<?php

namespace Database\Seeders;

use App\Models\BandScoreConversion;
use App\Models\MockTest;
use App\Models\MockTestSection;
use App\Models\Question;
use App\Models\QuestionAcceptedAnswer;
use App\Models\QuestionGroup;
use App\Models\QuestionOption;
use App\Models\QuestionPassage;
use Illuminate\Database\Seeder;

class DemoReadingMockSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBandTable('academic');
        $this->seedBandTable('general');

        $topics = [
            ['slug' => 'urban-parks-academic', 'title' => 'Urban Parks (Academic)', 'exam_type' => 'academic', 'passage_title' => 'Urban Green Spaces'],
            ['slug' => 'workplace-safety-general', 'title' => 'Workplace Safety (General)', 'exam_type' => 'general', 'passage_title' => 'Office Safety Notice'],
            ['slug' => 'reading-climate-01', 'title' => 'Climate Adaptation 01', 'exam_type' => 'academic', 'passage_title' => 'Rising Temperatures'],
            ['slug' => 'reading-library-02', 'title' => 'Library Guide 02', 'exam_type' => 'general', 'passage_title' => 'Using the Library'],
            ['slug' => 'reading-oceans-03', 'title' => 'Ocean Currents 03', 'exam_type' => 'academic', 'passage_title' => 'Ocean Currents'],
            ['slug' => 'reading-housing-04', 'title' => 'Housing Notice 04', 'exam_type' => 'general', 'passage_title' => 'Tenant Handbook'],
            ['slug' => 'reading-history-05', 'title' => 'Ancient Trade 05', 'exam_type' => 'academic', 'passage_title' => 'Silk Routes'],
            ['slug' => 'reading-health-06', 'title' => 'Clinic Leaflet 06', 'exam_type' => 'general', 'passage_title' => 'Clinic Hours'],
            ['slug' => 'reading-tech-07', 'title' => 'Digital Literacy 07', 'exam_type' => 'academic', 'passage_title' => 'Online Learning'],
            ['slug' => 'reading-travel-08', 'title' => 'Travel Advice 08', 'exam_type' => 'general', 'passage_title' => 'Airport Tips'],
            ['slug' => 'reading-biology-09', 'title' => 'Bee Colonies 09', 'exam_type' => 'academic', 'passage_title' => 'Pollinators'],
            ['slug' => 'reading-sports-10', 'title' => 'Sports Club 10', 'exam_type' => 'general', 'passage_title' => 'Membership Rules'],
            ['slug' => 'reading-energy-11', 'title' => 'Renewable Energy 11', 'exam_type' => 'academic', 'passage_title' => 'Solar Farms'],
            ['slug' => 'reading-museum-12', 'title' => 'Museum Visit 12', 'exam_type' => 'general', 'passage_title' => 'Exhibition Guide'],
            ['slug' => 'reading-language-13', 'title' => 'Language Change 13', 'exam_type' => 'academic', 'passage_title' => 'Dialects'],
            ['slug' => 'reading-banking-14', 'title' => 'Bank Services 14', 'exam_type' => 'general', 'passage_title' => 'Account Guide'],
            ['slug' => 'reading-space-15', 'title' => 'Space Research 15', 'exam_type' => 'academic', 'passage_title' => 'Mars Missions'],
            ['slug' => 'reading-volunteer-16', 'title' => 'Volunteer Brief 16', 'exam_type' => 'general', 'passage_title' => 'Community Day'],
            ['slug' => 'reading-food-17', 'title' => 'Food Security 17', 'exam_type' => 'academic', 'passage_title' => 'Crop Yields'],
            ['slug' => 'reading-transport-18', 'title' => 'City Transport 18', 'exam_type' => 'general', 'passage_title' => 'Bus Network'],
        ];

        foreach ($topics as $topic) {
            $isAcademic = $topic['exam_type'] === 'academic';
            $this->seedTest([
                'slug' => $topic['slug'],
                'title' => $topic['title'],
                'exam_type' => $topic['exam_type'],
                'instructions' => $isAcademic
                    ? 'You should spend about 20 minutes on this passage. Answer all questions.'
                    : 'Read the notice and answer the questions. Transfer your answers carefully.',
                'passage_title' => $topic['passage_title'],
                'passage_body' => $isAcademic
                    ? "Many cities are expanding parks to improve public health. Local councils report higher visitor numbers each year.\n\n"
                        ."Researchers found that people who walk in parks at least twice a week report lower stress. Some cities also plant trees along busy roads to reduce heat.\n\n"
                        ."However, funding remains uneven. Wealthier districts often receive more investment, while smaller neighbourhood parks struggle to stay open after dark."
                    : "All staff must keep emergency exits clear at all times. Fire drills are held every three months.\n\n"
                        ."If you notice damaged equipment, report it to Facilities within 24 hours. First-aid kits are located near the reception desk and on floor 3.\n\n"
                        ."Visitors should sign in at reception. Unaccompanied visitors are not allowed in the warehouse area.",
            ]);
        }

        $this->command?->info('Demo reading mocks ready: 20 published tests at /reading-tests');
    }

    /**
     * @param  array{slug: string, title: string, exam_type: string, instructions: string, passage_title: string, passage_body: string}  $data
     */
    private function seedTest(array $data): void
    {
        $test = MockTest::query()->updateOrCreate(
            ['slug' => $data['slug']],
            [
                'module' => MockTest::MODULE_READING,
                'exam_type' => $data['exam_type'],
                'title' => $data['title'],
                'instructions' => $data['instructions'],
                'duration_seconds' => 1200,
                'is_published' => true,
            ],
        );

        $section = MockTestSection::query()->updateOrCreate(
            [
                'mock_test_id' => $test->id,
                'position' => 1,
            ],
            ['title' => 'Passage 1'],
        );

        $passage = QuestionPassage::query()->updateOrCreate(
            [
                'mock_test_section_id' => $section->id,
                'position' => 1,
            ],
            [
                'title' => $data['passage_title'],
                'body' => $data['passage_body'],
            ],
        );

        $mcqGroup = QuestionGroup::query()->updateOrCreate(
            [
                'mock_test_section_id' => $section->id,
                'position' => 1,
            ],
            [
                'question_passage_id' => $passage->id,
                'type' => QuestionGroup::TYPE_MULTIPLE_CHOICE,
                'instructions' => 'Choose the correct letter, A, B or C.',
            ],
        );

        $optionA = QuestionOption::query()->updateOrCreate(
            [
                'question_group_id' => $mcqGroup->id,
                'label' => 'A',
            ],
            [
                'content' => $data['exam_type'] === 'academic'
                    ? 'Visitor numbers fell every year'
                    : 'Fire drills happen every month',
                'position' => 1,
            ],
        );

        $optionB = QuestionOption::query()->updateOrCreate(
            [
                'question_group_id' => $mcqGroup->id,
                'label' => 'B',
            ],
            [
                'content' => $data['exam_type'] === 'academic'
                    ? 'Visitor numbers rose each year'
                    : 'Fire drills happen every three months',
                'position' => 2,
            ],
        );

        QuestionOption::query()->updateOrCreate(
            [
                'question_group_id' => $mcqGroup->id,
                'label' => 'C',
            ],
            [
                'content' => $data['exam_type'] === 'academic'
                    ? 'Parks were closed permanently'
                    : 'Staff must ignore damaged equipment',
                'position' => 3,
            ],
        );

        Question::query()->updateOrCreate(
            [
                'question_group_id' => $mcqGroup->id,
                'position' => 1,
            ],
            [
                'prompt' => $data['exam_type'] === 'academic'
                    ? 'What do local councils report about parks?'
                    : 'How often are fire drills held?',
                'marks' => 1,
                'correct_option_id' => $optionB->id,
            ],
        );

        $tfngGroup = QuestionGroup::query()->updateOrCreate(
            [
                'mock_test_section_id' => $section->id,
                'position' => 2,
            ],
            [
                'question_passage_id' => $passage->id,
                'type' => QuestionGroup::TYPE_TRUE_FALSE_NOT_GIVEN,
                'instructions' => 'Do the following statements agree with the information? Write True, False or Not Given.',
            ],
        );

        foreach ([['True', 1], ['False', 2], ['Not Given', 3]] as [$label, $position]) {
            QuestionOption::query()->updateOrCreate(
                [
                    'question_group_id' => $tfngGroup->id,
                    'label' => $label,
                ],
                [
                    'content' => $label,
                    'position' => $position,
                ],
            );
        }

        $trueId = QuestionOption::query()
            ->where('question_group_id', $tfngGroup->id)
            ->where('content', 'True')
            ->value('id');

        Question::query()->updateOrCreate(
            [
                'question_group_id' => $tfngGroup->id,
                'position' => 1,
            ],
            [
                'prompt' => $data['exam_type'] === 'academic'
                    ? 'Some cities plant trees along busy roads.'
                    : 'First-aid kits are available near reception.',
                'marks' => 1,
                'correct_option_id' => $trueId,
            ],
        );

        $completionGroup = QuestionGroup::query()->updateOrCreate(
            [
                'mock_test_section_id' => $section->id,
                'position' => 3,
            ],
            [
                'question_passage_id' => $passage->id,
                'type' => QuestionGroup::TYPE_SENTENCE_COMPLETION,
                'instructions' => 'Complete the sentence with NO MORE THAN TWO WORDS from the passage.',
            ],
        );

        $completion = Question::query()->updateOrCreate(
            [
                'question_group_id' => $completionGroup->id,
                'position' => 1,
            ],
            [
                'prompt' => $data['exam_type'] === 'academic'
                    ? 'Smaller neighbourhood parks struggle to stay open after ______.'
                    : 'Unaccompanied visitors are not allowed in the ______ area.',
                'marks' => 1,
                'correct_option_id' => null,
            ],
        );

        QuestionAcceptedAnswer::query()->updateOrCreate(
            [
                'question_id' => $completion->id,
                'answer_text' => $data['exam_type'] === 'academic' ? 'dark' : 'warehouse',
            ],
            [],
        );

        // silence unused var warning style for optionA when only B is correct
        unset($optionA);
    }

    private function seedBandTable(string $examType): void
    {
        $rows = [
            3 => 4.0,
            2 => 3.5,
            1 => 3.0,
            0 => 2.5,
        ];

        foreach ($rows as $raw => $band) {
            BandScoreConversion::query()->updateOrCreate(
                [
                    'module' => 'reading',
                    'exam_type' => $examType,
                    'raw_score' => $raw,
                ],
                ['band_score' => $band],
            );
        }
    }
}
