<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;

/**
 * Members who are left out of the game: never quoted, never shown as a
 * choice. Those who opted out, members of the groups the admin listed, and
 * members suspended right now (flarum/suspend). Applied when a quiz is made
 * AND every time one is shown, so opting out (or a suspension, or a change
 * to the groups) takes effect on today's and yesterday's quizzes at once.
 *
 * 🚨 One plain subquery per source, never a UNION: MySQL 5.7, MariaDB 10.3
 * and PostgreSQL reject the parenthesised union Laravel writes inside IN.
 */
class Exclusions
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    /** Leave the excluded members out of a query, by its user id column. */
    public function leaveOut(Builder $query, string $column): Builder
    {
        $query->whereNotIn($column, fn ($q) => $q->select('user_id')->from('who_said_it_players')->where('opted_out', true));

        if ($groups = $this->settings->groups()) {
            $query->whereNotIn($column, fn ($q) => $q->select('user_id')->from('group_user')->whereIn('group_id', $groups));
        }
        if ($this->extensions->isEnabled('flarum-suspend')) {
            $query->whereNotIn($column, fn ($q) => $q->select('id')->from('users')->where('suspended_until', '>', Carbon::now()));
        }

        return $query;
    }

    /**
     * Which of these members are left out (one query).
     *
     * @param array<int|null> $ids
     * @return array<int, true>
     */
    public function among(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return [];
        }

        $in = $this->leaveOut($this->db->table('users')->whereIn('id', $ids), 'id')->pluck('id')->all();

        return array_fill_keys(array_values(array_diff($ids, array_map('intval', $in))), true);
    }
}
