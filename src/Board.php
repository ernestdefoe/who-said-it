<?php

namespace ErnestDefoe\WhoSaidIt;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Everything on the page besides the member's own game: today's and the
 * all-time leaderboards and yesterday's answers. Shared by every viewer,
 * so each is cached briefly (yesterday's for longer: it's over).
 */
class Board
{
    public const TOP = 10;

    public function __construct(
        private readonly Quiz $quiz,
        private readonly Clock $clock,
        private readonly Cache $cache,
    ) {
    }

    /** @return array{players: int, top: list<array{user: int, score: int, correct: int, grid: string}>} */
    public function today(Day $day): array
    {
        return $this->cache->remember('ernestdefoe-who-said-it.today.'.$day->id, 30, function () use ($day) {
            return [
                'players' => Score::query()->where('day_id', $day->id)->whereNotNull('finished_at')->count(),
                'top' => Score::query()->where('day_id', $day->id)->whereNotNull('finished_at')
                    ->orderByDesc('score')->orderBy('finished_at')->limit(self::TOP)->get()
                    ->map(fn (Score $s) => ['user' => $s->user_id, 'score' => $s->score, 'correct' => $s->correct, 'grid' => $s->grid])
                    ->values()->all(),
            ];
        });
    }

    /** Where a finished member stands today (1 = first). */
    public function rank(Score $score): int
    {
        return 1 + Score::query()->where('day_id', $score->day_id)->whereNotNull('finished_at')
            ->where(fn ($q) => $q->where('score', '>', $score->score)
                ->orWhere(fn ($q) => $q->where('score', $score->score)->where('finished_at', '<', $score->finished_at)))
            ->count();
    }

    /** @return list<array{user: int, total: int, quizzes: int, correct: int, best: int, streak: int}> */
    public function allTime(): array
    {
        $today = $this->clock->today();
        $yesterday = $this->clock->yesterday();

        return $this->cache->remember('ernestdefoe-who-said-it.alltime.'.$today, 60, function () use ($today, $yesterday) {
            return Player::query()->where('quizzes', '>', 0)->orderByDesc('total_score')->orderByDesc('quizzes')->limit(self::TOP)->get()
                ->map(fn (Player $p) => [
                    'user' => $p->user_id,
                    'total' => $p->total_score,
                    'quizzes' => $p->quizzes,
                    'correct' => $p->correct_total,
                    'best' => $p->best_streak,
                    'streak' => $p->currentStreak($today, $yesterday),
                ])->values()->all();
        });
    }

    /**
     * Yesterday's quotes with their answers and how many got each right.
     * A quote whose post has since gone private or been removed is shown as
     * withdrawn, without its words.
     *
     * @return array{date: string, questions: list<array<string, mixed>>}|null
     */
    public function yesterday(): ?array
    {
        $date = $this->clock->yesterday();

        return $this->cache->remember('ernestdefoe-who-said-it.yesterday.'.$date, 600, function () use ($date) {
            $day = Day::query()->where('day', $date)->first();
            if (! $day) {
                return null;
            }

            $questions = $this->quiz->questions($day);
            $live = $this->quiz->live($questions);
            $posts = $this->quiz->presenter()->posts($questions->filter(fn (Question $q) => isset($live[$q->id]))->values()->all());
            $stats = Answer::query()->toBase()->whereIn('question_id', $questions->pluck('id'))->whereNotNull('answered_at')
                ->groupBy('question_id')->selectRaw('question_id, COUNT(*) as n, SUM(CASE WHEN correct THEN 1 ELSE 0 END) as hits')
                ->get()->keyBy('question_id');

            return [
                'date' => $date,
                'questions' => $questions->map(function (Question $q) use ($live, $posts, $stats) {
                    $isLive = isset($live[$q->id]);
                    $row = $this->quiz->reveal($q, null, $posts, $isLive);
                    $n = (int) ($stats->get($q->id)->n ?? 0);
                    $row['answered'] = $n;
                    $row['percent'] = $n ? (int) round(100 * (int) $stats->get($q->id)->hits / $n) : null;

                    return $row;
                })->values()->all(),
            ];
        });
    }

    /**
     * The whole page for one viewer.
     *
     * @return array<string, mixed>
     */
    public function page(User $actor): array
    {
        $canPlay = ! $actor->isGuest() && $actor->can(Settings::PLAY);
        $day = $this->quiz->generator()->today();
        $userIds = [];
        $out = [
            'date' => $this->clock->today(),
            'nextAt' => $this->clock->nextAt()->toIso8601String(),
            'canPlay' => $canPlay,
            'day' => null,
            'me' => null,
            'played' => [],
            'current' => null,
            // Being made by another request this very moment: the page asks again shortly.
            'pending' => ! $day && $this->quiz->generator()->busy,
        ];

        if ($day) {
            $today = $this->today($day);
            $out['day'] = ['count' => $day->question_count, 'players' => $today['players'], 'top' => $today['top']];
            $userIds = array_column($today['top'], 'user');
        }

        if ($canPlay) {
            $this->quiz->settle($actor->id);
            $out['me'] = $this->me($actor, $day);
            if ($day) {
                [$played, $current, $ids] = $this->mine($day, $actor);
                $out['played'] = $played;
                $out['current'] = $current;
                $userIds = array_merge($userIds, $ids);
            }
        }

        $out['allTime'] = $this->allTime();
        $out['yesterday'] = $this->yesterday();
        $userIds = array_merge($userIds, array_column($out['allTime'], 'user'));
        foreach ($out['yesterday']['questions'] ?? [] as $q) {
            $userIds = array_merge($userIds, $q['choices'], [$q['author']]);
        }

        $out['users'] = $this->quiz->presenter()->users($userIds);

        return $out;
    }

    /**
     * What the member has answered today (with the answers), and the quote
     * on screen if one is open: its words and faces only.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null, 2: list<int>}
     */
    private function mine(Day $day, User $actor): array
    {
        $questions = $this->quiz->questions($day);
        $answers = $this->quiz->answers($questions, $actor);
        if ($answers->isEmpty()) {
            return [[], null, []];
        }

        $live = $this->quiz->live($questions);
        $answered = $questions->filter(fn (Question $q) => $answers->get($q->id)?->answered_at !== null)->values();
        $posts = $this->quiz->presenter()->posts($answered->filter(fn (Question $q) => isset($live[$q->id]))->all());

        $played = [];
        $current = null;
        $ids = [];
        foreach ($questions as $q) {
            $a = $answers->get($q->id);
            if ($a?->answered_at) {
                $played[] = $this->quiz->reveal($q, $a, $posts, isset($live[$q->id]));
                $ids = array_merge($ids, $this->quiz->choices($q));
            } elseif ($a && isset($live[$q->id])) {
                $current = $this->quiz->served($q, $a);
                $ids = array_merge($ids, $this->quiz->choices($q));
            }
        }

        return [$played, $current, $ids];
    }

    /** Someone left the game (opted out, a group setting, an erase): yesterday's answers are drawn again. */
    public function forgetYesterday(): void
    {
        $this->cache->forget('ernestdefoe-who-said-it.yesterday.'.$this->clock->yesterday());
    }

    /** @return array<string, mixed> */
    public function me(User $actor, ?Day $day): array
    {
        $player = Player::query()->find($actor->id);
        $score = $day ? Score::query()->where('day_id', $day->id)->where('user_id', $actor->id)->first() : null;
        $today = $this->clock->today();

        return [
            'score' => $score->score ?? 0,
            'correct' => $score->correct ?? 0,
            'answered' => $score->answered ?? 0,
            'grid' => $score->grid ?? '',
            'finished' => (bool) $score?->finished_at,
            'rank' => $score?->finished_at ? $this->rank($score) : null,
            'streak' => $player ? $player->currentStreak($today, $this->clock->yesterday()) : 0,
            'best' => $player->best_streak ?? 0,
            'total' => $player->total_score ?? 0,
            'quizzes' => $player->quizzes ?? 0,
            'remind' => (bool) $player?->remind,
            'optedOut' => (bool) $player?->opted_out,
        ];
    }

}
