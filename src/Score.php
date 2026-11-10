<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $day_id
 * @property int $user_id
 * @property int $score
 * @property int $correct
 * @property int $answered
 * @property string $grid
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class Score extends AbstractModel
{
    protected $table = 'who_said_it_scores';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer', 'day_id' => 'integer', 'user_id' => 'integer', 'score' => 'integer', 'correct' => 'integer',
        'answered' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
