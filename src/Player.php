<?php

namespace ErnestDefoe\WhoSaidIt;

use Flarum\Database\AbstractModel;

/**
 * @property int $user_id
 * @property int $total_score
 * @property int $quizzes
 * @property int $correct_total
 * @property int $streak
 * @property int $best_streak
 * @property string|null $last_day
 * @property bool $remind
 * @property bool $opted_out
 */
class Player extends AbstractModel
{
    protected $table = 'who_said_it_players';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer', 'total_score' => 'integer', 'quizzes' => 'integer', 'correct_total' => 'integer',
        'streak' => 'integer', 'best_streak' => 'integer', 'remind' => 'boolean', 'opted_out' => 'boolean',
    ];

    /** The streak as it stands today: one missed day and it's gone. */
    public function currentStreak(string $today, string $yesterday): int
    {
        return in_array($this->last_day, [$today, $yesterday], true) ? $this->streak : 0;
    }
}
