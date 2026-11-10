<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $day_id
 * @property int $position
 * @property int|null $post_id
 * @property int|null $discussion_id
 * @property int|null $author_id
 * @property string $excerpt
 * @property string $choices
 * @property Carbon $created_at
 */
class Question extends AbstractModel
{
    protected $table = 'who_said_it_questions';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer', 'day_id' => 'integer', 'position' => 'integer', 'post_id' => 'integer',
        'discussion_id' => 'integer', 'author_id' => 'integer', 'created_at' => 'datetime',
    ];

    /** @return list<int> */
    public function choiceIds(): array
    {
        return array_map('intval', array_filter(explode(',', $this->choices)));
    }
}
