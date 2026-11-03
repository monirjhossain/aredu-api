<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoCourseSeeder extends Seeder
{
    public function run(): void
    {
        $teacher1 = User::query()->where('email', 'teacher1@areducation.test')->first();
        $teacher2 = User::query()->where('email', 'teacher2@areducation.test')->first();

        if ($teacher1 === null || $teacher2 === null) {
            $this->command?->warn('DemoCourseSeeder skipped: demo teachers missing. Run DemoUserSeeder first.');

            return;
        }

        $academic = $this->category('academic-prep', 'Academic Prep');
        $general = $this->category('general-training', 'General Training');
        $skills = $this->category('skill-labs', 'Skill Labs');

        $courses = [
            [
                'slug' => 'academic-band-65-path',
                'title' => 'Academic Band 6.5 Path',
                'description' => 'A structured Academic path covering all four skills with guided practice and checkpoints.',
                'category_id' => $academic->id,
                'exam_type' => 'academic',
                'price' => 7800,
                'discount_price' => 5070,
                'duration_minutes' => 1800,
                'teachers' => [
                    ['user' => $teacher1, 'can_manage_content' => true],
                    ['user' => $teacher2, 'can_manage_content' => false],
                ],
                'sections' => [
                    [
                        'title' => 'Getting started',
                        'lessons' => [
                            ['title' => 'Welcome and study plan', 'free' => true, 'seconds' => 420],
                            ['title' => 'How band scoring works', 'free' => false, 'seconds' => 540],
                        ],
                    ],
                    [
                        'title' => 'Reading foundations',
                        'lessons' => [
                            ['title' => 'Skimming and scanning habits', 'free' => false, 'seconds' => 780],
                            ['title' => 'True / False / Not Given strategy', 'free' => false, 'seconds' => 900],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'academic-writing-intensive',
                'title' => 'Academic Writing Intensive',
                'description' => 'Task 1 and Task 2 drafting practice with teacher feedback loops.',
                'category_id' => $academic->id,
                'exam_type' => 'academic',
                'price' => 4500,
                'discount_price' => null,
                'duration_minutes' => 960,
                'teachers' => [
                    ['user' => $teacher1, 'can_manage_content' => true],
                ],
                'sections' => [
                    [
                        'title' => 'Task 1 craft',
                        'lessons' => [
                            ['title' => 'Describe trends clearly', 'free' => true, 'seconds' => 600],
                            ['title' => 'Compare groups without copying data', 'free' => false, 'seconds' => 720],
                        ],
                    ],
                    [
                        'title' => 'Task 2 craft',
                        'lessons' => [
                            ['title' => 'Plan before you write', 'free' => false, 'seconds' => 660],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'general-training-foundation',
                'title' => 'General Training Foundation',
                'description' => 'Everyday English skills for General Training candidates aiming for work or migration goals.',
                'category_id' => $general->id,
                'exam_type' => 'general',
                'price' => 6200,
                'discount_price' => 4030,
                'duration_minutes' => 1500,
                'teachers' => [
                    ['user' => $teacher2, 'can_manage_content' => true],
                ],
                'sections' => [
                    [
                        'title' => 'Orientation',
                        'lessons' => [
                            ['title' => 'GT exam overview', 'free' => true, 'seconds' => 480],
                            ['title' => 'Weekly practice rhythm', 'free' => false, 'seconds' => 360],
                        ],
                    ],
                    [
                        'title' => 'Letter writing',
                        'lessons' => [
                            ['title' => 'Formal vs informal tone', 'free' => false, 'seconds' => 700],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'listening-lab-academic',
                'title' => 'Listening Lab Academic',
                'description' => 'Timed listening drills focused on map labels, form completion, and multi-choice traps.',
                'category_id' => $skills->id,
                'exam_type' => 'academic',
                'price' => 3200,
                'discount_price' => null,
                'duration_minutes' => 720,
                'teachers' => [
                    ['user' => $teacher2, 'can_manage_content' => true],
                ],
                'sections' => [
                    [
                        'title' => 'Core drills',
                        'lessons' => [
                            ['title' => 'Predict before you hear', 'free' => true, 'seconds' => 510],
                            ['title' => 'Spelling under pressure', 'free' => false, 'seconds' => 450],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'speaking-confidence-gt',
                'title' => 'Speaking Confidence GT',
                'description' => 'Part 1–3 fluency routines with cue-card templates for General Training speakers.',
                'category_id' => $general->id,
                'exam_type' => 'general',
                'price' => 3800,
                'discount_price' => 2990,
                'duration_minutes' => 840,
                'teachers' => [
                    ['user' => $teacher1, 'can_manage_content' => true],
                ],
                'sections' => [
                    [
                        'title' => 'Fluency routines',
                        'lessons' => [
                            ['title' => 'Warm-up answers that sound natural', 'free' => true, 'seconds' => 390],
                            ['title' => 'Expand without memorizing scripts', 'free' => false, 'seconds' => 570],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'full-skills-academic',
                'title' => 'Full Skills Academic',
                'description' => 'All-skills Academic package with mocks, video lessons, and teacher checkpoints.',
                'category_id' => $academic->id,
                'exam_type' => 'academic',
                'price' => 9800,
                'discount_price' => 6860,
                'duration_minutes' => 2400,
                'teachers' => [
                    ['user' => $teacher1, 'can_manage_content' => true],
                    ['user' => $teacher2, 'can_manage_content' => true],
                ],
                'sections' => [
                    [
                        'title' => 'Kickoff',
                        'lessons' => [
                            ['title' => 'Course map and milestones', 'free' => true, 'seconds' => 430],
                        ],
                    ],
                    [
                        'title' => 'Integrated practice',
                        'lessons' => [
                            ['title' => 'Link listening notes to speaking answers', 'free' => false, 'seconds' => 640],
                            ['title' => 'Reading evidence for writing claims', 'free' => false, 'seconds' => 710],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($courses as $payload) {
            $course = Course::query()->updateOrCreate(
                ['slug' => $payload['slug']],
                [
                    'category_id' => $payload['category_id'],
                    'title' => $payload['title'],
                    'description' => $payload['description'],
                    'exam_type' => $payload['exam_type'],
                    'price' => $payload['price'],
                    'discount_price' => $payload['discount_price'],
                    'duration_minutes' => $payload['duration_minutes'],
                    'is_published' => true,
                ],
            );

            $sync = [];
            foreach ($payload['teachers'] as $assignment) {
                $sync[$assignment['user']->id] = [
                    'can_manage_content' => $assignment['can_manage_content'],
                ];
            }
            $course->teachers()->sync($sync);

            foreach ($payload['sections'] as $sectionIndex => $sectionData) {
                $section = CourseSection::query()->updateOrCreate(
                    [
                        'course_id' => $course->id,
                        'position' => $sectionIndex + 1,
                    ],
                    ['title' => $sectionData['title']],
                );

                foreach ($sectionData['lessons'] as $lessonIndex => $lessonData) {
                    Lesson::query()->updateOrCreate(
                        [
                            'course_section_id' => $section->id,
                            'position' => $lessonIndex + 1,
                        ],
                        [
                            'title' => $lessonData['title'],
                            'duration_seconds' => $lessonData['seconds'],
                            'is_free_preview' => $lessonData['free'],
                            'is_published' => true,
                        ],
                    );
                }
            }
        }

        $this->command?->info('Demo courses ready: '.count($courses).' published courses with sections and free previews.');
    }

    private function category(string $slug, string $name): CourseCategory
    {
        $category = CourseCategory::withTrashed()->firstOrNew(['slug' => $slug]);
        $category->name = $name;
        if ($category->trashed()) {
            $category->restore();
        }
        $category->save();

        return $category;
    }
}
