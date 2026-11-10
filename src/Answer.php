<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $question_id
 * @property int $user_id
 * @property int $shown_ms
 * @property Carbon|null $answered_at
 * @property int|null $choice_id
 * @property bool|null $correct
 * @property int $points
 * @property int|null $ms
 */
class Answer extends AbstractModel
{
    protected $table = 'who_said_it_answers';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer', 'question_id' => 'integer', 'user_id' => 'integer', 'shown_ms' => 'integer',
        'answered_at' => 'datetime', 'choice_id' => 'integer', 'correct' => 'boolean', 'points' => 'integer', 'ms' => 'integer',
    ];
}
