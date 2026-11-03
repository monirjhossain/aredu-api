<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionGroup extends Model
{
    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';

    public const TYPE_TRUE_FALSE_NOT_GIVEN = 'true_false_not_given';

    public const TYPE_YES_NO_NOT_GIVEN = 'yes_no_not_given';

    public const TYPE_MATCHING_HEADINGS = 'matching_headings';

    public const TYPE_MATCHING_INFORMATION = 'matching_information';

    public const TYPE_SENTENCE_COMPLETION = 'sentence_completion';

    public const TYPE_SUMMARY_COMPLETION = 'summary_completion';

    public const TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TRUE_FALSE_NOT_GIVEN,
        self::TYPE_YES_NO_NOT_GIVEN,
        self::TYPE_MATCHING_HEADINGS,
        self::TYPE_MATCHING_INFORMATION,
        self::TYPE_SENTENCE_COMPLETION,
        self::TYPE_SUMMARY_COMPLETION,
    ];

    public const CHOICE_TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TRUE_FALSE_NOT_GIVEN,
        self::TYPE_YES_NO_NOT_GIVEN,
        self::TYPE_MATCHING_HEADINGS,
        self::TYPE_MATCHING_INFORMATION,
    ];

    public const COMPLETION_TYPES = [
        self::TYPE_SENTENCE_COMPLETION,
        self::TYPE_SUMMARY_COMPLETION,
    ];

    protected $fillable = [
        'mock_test_section_id',
        'question_passage_id',
        'type',
        'instructions',
        'position',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(MockTestSection::class, 'mock_test_section_id');
    }

    public function passage(): BelongsTo
    {
        return $this->belongsTo(QuestionPassage::class, 'question_passage_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position')->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position')->orderBy('id');
    }

    public function usesOptions(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    public function usesAcceptedAnswers(): bool
    {
        return in_array($this->type, self::COMPLETION_TYPES, true);
    }
}
