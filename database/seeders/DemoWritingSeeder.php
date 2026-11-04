<?php

namespace Database\Seeders;

use App\Models\WritingTask;
use Illuminate\Database\Seeder;

class DemoWritingSeeder extends Seeder
{
    public function run(): void
    {
        $tasks = array_merge($this->academicTask1(), $this->generalTask1(), $this->task2());

        foreach ($tasks as $task) {
            WritingTask::query()->updateOrCreate(
                ['title' => $task['title']],
                [
                    'exam_type' => $task['exam_type'],
                    'task_type' => $task['task_type'],
                    'instructions' => $task['instructions'],
                    'prompt' => $task['prompt'],
                    'recommended_minutes' => $task['task_type'] === WritingTask::TYPE_TASK_2 ? 40 : 20,
                    'min_words' => $task['task_type'] === WritingTask::TYPE_TASK_2 ? 250 : 150,
                    'is_published' => true,
                ],
            );
        }

        $this->command?->info('Demo writing tasks ready: '.count($tasks).' published tasks at /writing-tasks');
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function academicTask1(): array
    {
        $instructions = 'You should spend about 20 minutes on this task. Summarise the information by selecting and reporting the main features, and make comparisons where relevant. Write at least 150 words.';
        $items = [
            ['Line Graph: Internet Users by Region', 'The line graph shows the percentage of the population using the internet in four regions between 2000 and 2020.'],
            ['Bar Chart: University Enrolment by Subject', 'The bar chart compares the number of students enrolled in five subject areas at one university in 2010 and 2020.'],
            ['Pie Charts: Household Energy Use', 'The two pie charts show how a typical household used energy in 1990 and in 2020.'],
            ['Table: Average Monthly Rainfall', 'The table gives the average monthly rainfall in four cities over a twelve-month period.'],
            ['Process Diagram: Recycling Plastic Bottles', 'The diagram illustrates the stages in the process of recycling plastic bottles into new products.'],
            ['Map: Town Centre Redevelopment', 'The two maps show the town centre of Hartley in 1995 and as it is today.'],
            ['Line Graph: Coffee and Tea Consumption', 'The graph shows annual coffee and tea consumption per person in three countries from 2005 to 2020.'],
            ['Bar Chart: Commuting Methods', 'The bar chart shows the main methods of commuting to work in a European city in 2000, 2010 and 2020.'],
        ];

        return array_map(fn (array $item) => [
            'title' => $item[0],
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_ACADEMIC_TASK_1,
            'instructions' => $instructions,
            'prompt' => $item[1],
        ], $items);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function generalTask1(): array
    {
        $instructions = 'You should spend about 20 minutes on this task. Write at least 150 words. You do NOT need to write any addresses.';
        $items = [
            ['Letter: Complaint About a Faulty Product', 'You bought a laptop online but it stopped working after one week. Write a letter to the company. Explain what happened, describe the problem, and say what you would like them to do.'],
            ['Letter: Invitation to a Friend', 'A friend from abroad is planning to visit your city. Write a letter inviting them to stay with you. Suggest dates, describe what you could do together, and explain how to get to your home.'],
            ['Letter: Request for Information About a Course', 'You are interested in an evening English course at a local college. Write a letter asking about class times, fees, and the level of the course.'],
            ['Letter: Neighbour Noise Problem', 'Your neighbour often plays loud music late at night. Write a polite letter to explain the problem, say how it affects you, and suggest a solution.'],
            ['Letter: Thank-You to a Host Family', 'You recently stayed with a host family while studying abroad. Write a letter to thank them, describe what you enjoyed, and invite them to visit you.'],
            ['Letter: Lost Luggage at the Airport', 'Your luggage did not arrive when you flew home last week. Write to the airline, describe the bag and its contents, and say what compensation you expect.'],
            ['Letter: Job Application for a Summer Role', 'You saw an advertisement for a summer job at a language school. Write a letter explaining why you are suitable and describing your relevant experience.'],
            ['Letter: Change of Meeting Date', 'You are unable to attend a meeting with your manager next week. Write a letter explaining why and suggesting two alternative dates.'],
        ];

        return array_map(fn (array $item) => [
            'title' => $item[0],
            'exam_type' => 'general',
            'task_type' => WritingTask::TYPE_GENERAL_TASK_1,
            'instructions' => $instructions,
            'prompt' => $item[1],
        ], $items);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function task2(): array
    {
        $instructions = 'You should spend about 40 minutes on this task. Give reasons for your answer and include any relevant examples from your own knowledge or experience. Write at least 250 words.';
        $items = [
            ['Essay: Technology in Education', 'Some people believe that technology has made learning easier and more effective. Others think it distracts students. Discuss both views and give your own opinion.'],
            ['Essay: Working from Home', 'More people now work from home rather than in an office. Do the advantages of this trend outweigh the disadvantages?'],
            ['Essay: Public Transport vs Private Cars', 'Some say governments should invest more in public transport to reduce traffic. To what extent do you agree or disagree?'],
            ['Essay: Fast Food and Health', 'The consumption of fast food is rising worldwide. What problems does this cause, and what measures can be taken to solve them?'],
            ['Essay: Children and Screen Time', 'Many children spend several hours a day looking at screens. What are the causes of this, and is it a positive or negative development?'],
            ['Essay: Protecting the Environment', 'Some people think individuals can do little to protect the environment and that only governments can make a difference. Do you agree or disagree?'],
            ['Essay: Learning Foreign Languages', 'Some people say that children should start learning a foreign language at primary school. Others believe secondary school is better. Discuss both views and give your opinion.'],
            ['Essay: Tourism and Local Culture', 'International tourism has brought benefits to many countries, but it can damage local culture. Do the advantages outweigh the disadvantages?'],
        ];

        return array_map(fn (array $item, int $index) => [
            'title' => $item[0],
            'exam_type' => $index % 2 === 0 ? 'academic' : 'general',
            'task_type' => WritingTask::TYPE_TASK_2,
            'instructions' => $instructions,
            'prompt' => $item[1],
        ], $items, array_keys($items));
    }
}
