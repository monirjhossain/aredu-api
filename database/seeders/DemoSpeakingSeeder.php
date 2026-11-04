<?php

namespace Database\Seeders;

use App\Models\SpeakingQuestion;
use App\Models\SpeakingTask;
use Illuminate\Database\Seeder;

class DemoSpeakingSeeder extends Seeder
{
    public function run(): void
    {
        $tasks = array_merge($this->part1(), $this->part2(), $this->part3());

        foreach ($tasks as $item) {
            $task = SpeakingTask::query()->updateOrCreate(
                ['title' => $item['title']],
                [
                    'part' => $item['part'],
                    'instructions' => $item['instructions'],
                    'preparation_seconds' => $item['preparation_seconds'],
                    'response_seconds' => $item['response_seconds'],
                    'is_published' => true,
                ],
            );

            $positions = [];
            foreach ($item['questions'] as $index => $question) {
                $position = $index + 1;
                $positions[] = $position;

                SpeakingQuestion::query()->updateOrCreate(
                    ['speaking_task_id' => $task->id, 'position' => $position],
                    [
                        'prompt' => $question['prompt'],
                        'cue_card' => $question['cue_card'] ?? null,
                    ],
                );
            }

            SpeakingQuestion::query()
                ->where('speaking_task_id', $task->id)
                ->whereNotIn('position', $positions)
                ->delete();
        }

        $this->command?->info('Demo speaking tasks ready: '.count($tasks).' published tasks at /speaking-tasks');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function part1(): array
    {
        $instructions = 'Part 1: The examiner asks you general questions about familiar topics. Answer each question naturally in two or three sentences.';
        $topics = [
            ['Part 1: Hometown', ['Where is your hometown?', 'What do you like most about your hometown?', 'Has your hometown changed much in recent years?', 'Would you like to live there in the future?']],
            ['Part 1: Work and Studies', ['Do you work or are you a student?', 'Why did you choose that job or subject?', 'What do you enjoy most about it?', 'Do you plan to change your job or field of study?']],
            ['Part 1: Home and Accommodation', ['Do you live in a house or an apartment?', 'Which room do you spend most time in?', 'What would you like to change about your home?']],
            ['Part 1: Daily Routine', ['What time do you usually get up?', 'Which part of the day do you like best?', 'Do you prefer to plan your day or be spontaneous?']],
            ['Part 1: Food and Cooking', ['What is your favourite food?', 'Do you often cook at home?', 'Has the food you eat changed since you were a child?']],
            ['Part 1: Hobbies and Free Time', ['What do you like to do in your free time?', 'Do you prefer indoor or outdoor activities?', 'Have you taken up any new hobbies recently?']],
            ['Part 1: Weather and Seasons', ['What is the weather like in your country?', 'Which season do you like best, and why?', 'Does the weather affect your mood?']],
            ['Part 1: Transport', ['How do you usually travel to work or school?', 'Is public transport good in your city?', 'Would you like to own a car in the future?']],
        ];

        return array_map(fn (array $topic) => [
            'part' => SpeakingTask::PART_1,
            'title' => $topic[0],
            'instructions' => $instructions,
            'preparation_seconds' => 0,
            'response_seconds' => 60,
            'questions' => array_map(fn (string $prompt) => ['prompt' => $prompt], $topic[1]),
        ], $topics);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function part2(): array
    {
        $instructions = 'Part 2: You will have one minute to prepare. You may make notes. Then speak for one to two minutes about the topic on the cue card.';
        $cards = [
            ['Part 2: Describe a Memorable Journey', 'Describe a journey you remember well.', "You should say:\n- where you went\n- who you went with\n- how you travelled\nand explain why this journey was memorable."],
            ['Part 2: Describe a Person Who Inspired You', 'Describe a person who has inspired you.', "You should say:\n- who this person is\n- how you know them\n- what they have achieved\nand explain why they inspire you."],
            ['Part 2: Describe a Favourite Book or Film', 'Describe a book or film you enjoyed.', "You should say:\n- what it was\n- what it was about\n- when you read or watched it\nand explain why you enjoyed it."],
            ['Part 2: Describe a Place You Like to Visit', 'Describe a place where you like to spend your free time.', "You should say:\n- where it is\n- how often you go there\n- what you do there\nand explain why you like it."],
            ['Part 2: Describe a Time You Helped Someone', 'Describe a time when you helped another person.', "You should say:\n- who you helped\n- what the problem was\n- how you helped\nand explain how you felt afterwards."],
            ['Part 2: Describe a Useful Piece of Technology', 'Describe a piece of technology you find useful.', "You should say:\n- what it is\n- how often you use it\n- what you use it for\nand explain why it is useful to you."],
            ['Part 2: Describe a Celebration', 'Describe a celebration you attended.', "You should say:\n- what the celebration was\n- who was there\n- what happened\nand explain why you remember it."],
            ['Part 2: Describe Something You Want to Learn', 'Describe a skill you would like to learn in the future.', "You should say:\n- what the skill is\n- why you want to learn it\n- how you would learn it\nand explain how it would benefit you."],
        ];

        return array_map(fn (array $card) => [
            'part' => SpeakingTask::PART_2,
            'title' => $card[0],
            'instructions' => $instructions,
            'preparation_seconds' => 60,
            'response_seconds' => 120,
            'questions' => [
                ['prompt' => $card[1], 'cue_card' => $card[2]],
                ['prompt' => 'Do you think other people would enjoy this too?'],
            ],
        ], $cards);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function part3(): array
    {
        $instructions = 'Part 3: The examiner asks deeper, more abstract questions related to the Part 2 topic. Give extended answers with reasons and examples.';
        $topics = [
            ['Part 3: Travel and Tourism', ['Why do so many people enjoy travelling abroad?', 'What are the negative effects of mass tourism?', 'How might travel change in the next twenty years?']],
            ['Part 3: Role Models and Leadership', ['Why do people need role models?', 'Are famous people good role models for young people?', 'What qualities make a good leader?']],
            ['Part 3: Reading and Media', ['Do people read less now than in the past?', 'How has the internet changed the way people consume stories?', 'Should schools encourage children to read more books?']],
            ['Part 3: Cities and Public Spaces', ['How important are parks and green spaces in cities?', 'What problems do large cities face?', 'Should governments spend more on public spaces?']],
            ['Part 3: Community and Volunteering', ['Why do some people volunteer their time?', 'Is it the government’s role or individuals’ role to help the community?', 'How can communities encourage young people to help others?']],
            ['Part 3: Technology and Society', ['How has technology changed the way people communicate?', 'Do you think people depend too much on technology?', 'What impact will artificial intelligence have on jobs?']],
            ['Part 3: Traditions and Festivals', ['Why are traditional festivals important to a society?', 'Are traditions disappearing in modern life?', 'Should festivals be more commercial or more cultural?']],
            ['Part 3: Education and Lifelong Learning', ['Why is it important to keep learning throughout life?', 'Is online learning as effective as classroom learning?', 'What skills will be most valuable in the future?']],
        ];

        return array_map(fn (array $topic) => [
            'part' => SpeakingTask::PART_3,
            'title' => $topic[0],
            'instructions' => $instructions,
            'preparation_seconds' => 0,
            'response_seconds' => 90,
            'questions' => array_map(fn (string $prompt) => ['prompt' => $prompt], $topic[1]),
        ], $topics);
    }
}
