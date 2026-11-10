<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Playing a day: serving the next quote, checking an answer, finishing.
 *
 * 🔒 The answer (author_id, post_id, discussion_id) leaves this class only
 * in reveal(), and reveal() is only called for a quote the member has
 * already answered (or for yesterday's, which are over). A quote is served
 * as its excerpt and the four choices, nothing else.
 */
class Quiz
{
    public function __construct(
        private readonly Generator $generator,
        private readonly Clock $clock,
        private readonly Presenter $presenter,
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
    ) {
    }

    /** @return Collection<int, Question> */
    public function questions(Day $day): Collection
    {
        return Question::query()->where('day_id', $day->id)->orderBy('position')->get();
    }

    /**
     * Quotes still fit to play: the post still exists, its author too, and a
     * guest can still read it (not hidden, moved to a private tag or deleted
     * since the quiz was made). The rest are withdrawn.
     *
     * @param Collection<int, Question> $questions
     * @return array<int, true> question id => true
     */
    public function live(Collection $questions): array
    {
        $postIds = $questions->filter(fn (Question $q) => $q->post_id && $q->author_id)->pluck('post_id')->all();
        $visible = $postIds ? array_flip(Post::query()->whereVisibleTo(new Guest())->whereIn('posts.id', $postIds)->pluck('posts.id')->all()) : [];

        $live = [];
        foreach ($questions as $q) {
            if ($q->post_id && $q->author_id && isset($visible[$q->post_id])) {
                $live[$q->id] = true;
            }
        }

        return $live;
    }

    /**
     * @param Collection<int, Question> $questions
     * @return Collection<int, Answer> keyed by question id
     */
    public function answers(Collection $questions, User $actor): Collection
    {
        return Answer::query()->whereIn('question_id', $questions->pluck('id'))->where('user_id', $actor->id)->get()->keyBy('question_id');
    }

    /**
     * The quote the member is on: the one already showing if there is one
     * (its clock keeps running across reloads), else the next one, whose
     * clock starts now. Null once there are none left.
     *
     * @return array{question: Question, answer: Answer}|null
     */
    public function next(Day $day, User $actor): ?array
    {
        $questions = $this->questions($day);
        $live = $this->live($questions);
        $answers = $this->answers($questions, $actor);

        foreach ($questions as $q) {
            $a = $answers->get($q->id);
            if (isset($live[$q->id]) && $a && ! $a->answered_at) {
                return ['question' => $q, 'answer' => $a];
            }
        }

        foreach ($questions as $q) {
            if (! isset($live[$q->id]) || $answers->has($q->id)) {
                continue;
            }

            $this->startScore($day, $actor);

            $a = new Answer();
            $a->question_id = $q->id;
            $a->user_id = $actor->id;
            $a->shown_ms = Carbon::now()->getTimestampMs();
            try {
                $a->save();
            } catch (QueryException) {
                // A double tap raced us: the first one's clock stands.
                $a = Answer::query()->where('question_id', $q->id)->where('user_id', $actor->id)->firstOrFail();
            }

            return ['question' => $q, 'answer' => $a];
        }

        $this->finish($day, $actor, $questions, $live);

        return null;
    }

    private function startScore(Day $day, User $actor): void
    {
        if (Score::query()->where('day_id', $day->id)->where('user_id', $actor->id)->exists()) {
            return;
        }

        $score = new Score();
        $score->day_id = $day->id;
        $score->user_id = $actor->id;
        $score->grid = str_repeat(Scoring::OPEN, $day->question_count);
        $score->started_at = Carbon::now();
        try {
            $score->save();
        } catch (QueryException) {
            // Made by a racing request.
        }
    }

    /**
     * Check one answer. The quote must be today's, already shown to this
     * member, not yet answered, and still live; the choice must be one of
     * its four. One guarded UPDATE records it, so two taps can't score twice.
     *
     * @return array{0: string|null, 1: Answer|null} [error code, the answer]
     */
    public function answer(User $actor, Question $question, int $choice): array
    {
        $day = Day::query()->find($question->day_id);
        if (! $day || $day->day !== $this->clock->today()) {
            return ['wsi_closed', null];
        }
        if (! in_array($choice, $question->choiceIds(), true)) {
            return ['wsi_bad_choice', null];
        }

        $answer = Answer::query()->where('question_id', $question->id)->where('user_id', $actor->id)->first();
        if (! $answer) {
            return ['wsi_not_shown', null];
        }
        if ($answer->answered_at) {
            return ['wsi_answered', $answer];
        }
        if (! $this->live(collect([$question]))) {
            return ['wsi_withdrawn', null];
        }

        $ms = (int) max(0, Carbon::now()->getTimestampMs() - $answer->shown_ms);
        $correct = $choice === $question->author_id;
        $points = Scoring::points($correct, $ms);

        $recorded = $this->db->transaction(function () use ($answer, $choice, $correct, $points, $ms, $day, $actor, $question) {
            $updated = Answer::query()->whereKey($answer->id)->whereNull('answered_at')->update([
                'answered_at' => Carbon::now(),
                'choice_id' => $choice,
                'correct' => $correct,
                'points' => $points,
                'ms' => $ms,
            ]);
            if (! $updated) {
                return false;
            }

            /** @var Score|null $score */
            $score = Score::query()->where('day_id', $day->id)->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($score) {
                $grid = str_pad($score->grid, $day->question_count, Scoring::OPEN);
                $grid[$question->position - 1] = Scoring::letter($correct, $ms);
                $score->grid = $grid;
                $score->score += $points;
                $score->correct += $correct ? 1 : 0;
                $score->answered += 1;
                $score->save();
            }

            return true;
        });

        $answer->refresh();
        if (! $recorded) {
            return ['wsi_answered', $answer];
        }

        $questions = $this->questions($day);
        $live = $this->live($questions);
        $answers = $this->answers($questions, $actor);
        if (! $questions->contains(fn (Question $q) => isset($live[$q->id]) && ! $answers->get($q->id)?->answered_at)) {
            $this->finish($day, $actor, $questions, $live);
        }

        return [null, $answer];
    }

    /**
     * The member has nothing left to answer today: stamp the day finished,
     * mark withdrawn quotes on the grid, and add the day to their all-time
     * record and streak. Locked and checked, so it happens once.
     *
     * @param Collection<int, Question> $questions
     * @param array<int, true> $live
     */
    public function finish(Day $day, User $actor, Collection $questions, array $live): void
    {
        $finished = $this->db->transaction(function () use ($day, $actor, $questions, $live) {
            /** @var Score|null $score */
            $score = Score::query()->where('day_id', $day->id)->where('user_id', $actor->id)->lockForUpdate()->first();
            if (! $score || $score->finished_at) {
                return false;
            }

            $grid = str_pad($score->grid, $day->question_count, Scoring::OPEN);
            foreach ($questions as $q) {
                if (! isset($live[$q->id]) && ($grid[$q->position - 1] ?? '') === Scoring::OPEN) {
                    $grid[$q->position - 1] = Scoring::VOID;
                }
            }
            $score->grid = str_replace(Scoring::OPEN, Scoring::VOID, $grid);
            $score->finished_at = Carbon::now();
            $score->save();

            $player = $this->player($actor->id, true);
            $yesterday = Carbon::parse($day->day)->subDay()->toDateString();
            if ($player->last_day !== $day->day) {
                $player->streak = $player->last_day === $yesterday ? $player->streak + 1 : 1;
                $player->best_streak = max($player->best_streak, $player->streak);
                $player->quizzes += 1;
                $player->total_score += $score->score;
                $player->correct_total += $score->correct;
                $player->last_day = $day->day;
                $player->save();
            }

            return true;
        });

        if ($finished) {
            // Their name may be on the boards now.
            $this->cache->forget('ernestdefoe-who-said-it.today.'.$day->id);
            $this->cache->forget('ernestdefoe-who-said-it.alltime.'.$this->clock->today());
        }
    }

    /** The member's record, made on first use; locked when $lock (inside a transaction). */
    public function player(int $userId, bool $lock = false): Player
    {
        $query = Player::query()->whereKey($userId);
        $player = $lock ? $query->lockForUpdate()->first() : $query->first();
        if ($player) {
            return $player;
        }

        try {
            $this->db->table('who_said_it_players')->insert(['user_id' => $userId]);
        } catch (QueryException) {
            // Made by a racing request.
        }
        $query = Player::query()->whereKey($userId);

        return ($lock ? $query->lockForUpdate()->first() : $query->first()) ?? throw new \RuntimeException('player row missing');
    }

    /**
     * A quote as it's served while it's being played: the words and the four
     * faces. Never the author, the post or its discussion.
     *
     * @return array{id: int, position: int, excerpt: string, choices: list<int>, elapsedMs: int, limitMs: int}
     */
    public function served(Question $q, Answer $a): array
    {
        return [
            'id' => $q->id,
            'position' => $q->position,
            'excerpt' => $q->excerpt,
            'choices' => $q->choiceIds(),
            'elapsedMs' => (int) max(0, Carbon::now()->getTimestampMs() - $a->shown_ms),
            'limitMs' => Scoring::NO_BONUS_MS,
        ];
    }

    /**
     * A quote with its answer: only for one this member has answered, or a past day's.
     *
     * @param array<int, array{url: string, title: string}> $posts
     * @return array<string, mixed>
     */
    public function reveal(Question $q, ?Answer $a, array $posts, bool $live = true): array
    {
        return [
            'id' => $q->id,
            'position' => $q->position,
            'excerpt' => $q->excerpt,
            'choices' => $q->choiceIds(),
            'author' => $live ? $q->author_id : null,
            'post' => $live ? ($posts[$q->id] ?? null) : null,
            'withdrawn' => ! $live,
            'chosen' => $a?->choice_id,
            'correct' => $a?->correct,
            'points' => $a?->points ?? 0,
            'bonus' => $a && $a->correct ? $a->points - Scoring::BASE : 0,
            'ms' => $a?->ms,
        ];
    }

    public function presenter(): Presenter
    {
        return $this->presenter;
    }

    public function generator(): Generator
    {
        return $this->generator;
    }
}
