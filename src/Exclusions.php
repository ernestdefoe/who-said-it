<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Members who are left out of the game: never quoted, never shown as a
 * choice. Those who opted out, members of the groups the admin listed, and
 * members suspended right now (flarum/suspend). Applied when a quiz is made
 * AND every time one is shown, so opting out (or a suspension, or a change
 * to the groups) takes effect on today's and yesterday's quizzes at once.
 */
class Exclusions
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    /** A subquery of the excluded user ids: no list of ids, however many there are. */
    public function subquery(): \Closure
    {
        $groups = $this->settings->groups();
        $suspend = $this->extensions->isEnabled('flarum-suspend');

        return function (Builder $query) use ($groups, $suspend) {
            $query->select('user_id')->from('who_said_it_players')->where('opted_out', true);
            if ($groups) {
                $query->union($this->db->table('group_user')->select('user_id')->whereIn('group_id', $groups));
            }
            if ($suspend) {
                $query->union($this->db->table('users')->select('id')->where('suspended_until', '>', Carbon::now()));
            }
        };
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

        $out = $this->db->table('users')->whereIn('id', $ids)->whereIn('id', $this->subquery())->pluck('id')->all();

        return array_fill_keys(array_map('intval', $out), true);
    }
}
