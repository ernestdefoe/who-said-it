<?php

namespace ErnestDefoe\WhoSaidIt;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * The `whoSaidIt` forum attribute behind the sidebar card and the settings
 * switches: today's quiz from the cache (no query), and for a signed-in
 * member their day, streak and choices in ONE query. Never makes a quiz:
 * that's the scheduler's job, or the quiz page's.
 */
class ForumState
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Cache $cache,
        private readonly ConnectionInterface $db,
    ) {
    }

    public static function key(string $date): string
    {
        return 'ernestdefoe-who-said-it.day.'.$date;
    }

    /** @return array<string, mixed> */
    public function __invoke(User $actor): array
    {
        $date = $this->clock->today();
        /** @var array{0: int, 1: int} $day [id, count]; [0, 0] = not made yet */
        $day = $this->cache->remember(self::key($date), 300, function () use ($date) {
            $row = Day::query()->where('day', $date)->first(['id', 'question_count']);

            return [(int) $row?->id, (int) $row?->question_count];
        });

        $out = [
            'date' => $date,
            'nextAt' => $this->clock->nextAt()->toIso8601String(),
            'count' => $day[1],
            'canPlay' => ! $actor->isGuest() && $actor->can(Settings::PLAY),
            'me' => null,
        ];

        if (! $actor->isGuest()) {
            $row = $this->db->table('users')
                ->leftJoin('who_said_it_players', 'who_said_it_players.user_id', '=', 'users.id')
                ->leftJoin('who_said_it_scores', function ($join) use ($day) {
                    $join->on('who_said_it_scores.user_id', '=', 'users.id')->where('who_said_it_scores.day_id', '=', $day[0]);
                })
                ->where('users.id', $actor->id)
                ->first([
                    'who_said_it_players.streak', 'who_said_it_players.last_day', 'who_said_it_players.remind', 'who_said_it_players.opted_out',
                    'who_said_it_scores.score', 'who_said_it_scores.correct', 'who_said_it_scores.answered', 'who_said_it_scores.grid', 'who_said_it_scores.finished_at',
                ]);

            $lastDay = $row->last_day ?? null;
            $out['me'] = [
                'score' => (int) ($row->score ?? 0),
                'correct' => (int) ($row->correct ?? 0),
                'answered' => (int) ($row->answered ?? 0),
                'grid' => (string) ($row->grid ?? ''),
                'finished' => ! empty($row->finished_at),
                'streak' => in_array($lastDay, [$date, $this->clock->yesterday()], true) ? (int) $row->streak : 0,
                'remind' => (bool) ($row->remind ?? false),
                'optedOut' => (bool) ($row->opted_out ?? false),
            ];
        }

        return $out;
    }
}
