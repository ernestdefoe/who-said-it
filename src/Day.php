<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $day
 * @property int $question_count
 * @property Carbon|null $reminded_at
 * @property Carbon $created_at
 */
class Day extends AbstractModel
{
    protected $table = 'who_said_it_days';

    public $timestamps = false;

    protected $casts = ['id' => 'integer', 'question_count' => 'integer', 'reminded_at' => 'datetime', 'created_at' => 'datetime'];
}
