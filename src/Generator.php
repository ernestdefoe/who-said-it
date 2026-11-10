<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Random\Randomizer;

/**
 * Makes a day's quiz, once. Quotes come only from posts a GUEST can read
 * (core's own visibility rules, so private discussions, restricted tags,
 * Byobu, unapproved and hidden posts are all out), old enough, long enough
 * once cleaned, from authors who haven't opted out, and not used within the
 * repeat window. Each gets three other members with a similar post count.
 *
 * Idempotent: the day row is unique, and the day and its questions are
 * written in one transaction, so a second run (or a racing one) only reads
 * back what the first wrote.
 */
class Generator
{
    public const QUESTIONS = 5;

    /** Another request is making today's quiz right now: "on its way", not "no quiz". */
    public bool $busy = false;
    /** Fewer than this and there's no quiz today (a tiny or brand-new forum). */
    public const MIN_QUESTIONS = 3;
    private const NONE_TTL = 1800;

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly ExtensionManager $extensions,
        private readonly Exclusions $exclusions,
    ) {
    }

    /** Today's quiz, made now if the scheduler hasn't yet. Null when the forum can't fill one. */
    public function today(): ?Day
    {
        return $this->forDate($this->clock->today());
    }

    public function forDate(string $date, bool $force = false): ?Day
    {
        $day = Day::query()->where('day', $date)->first();
        if ($day || (! $force && $this->cache->get($this->noneKey($date)))) {
            return $day;
        }

        // One maker at a time: at midnight, before the scheduler's run, the first few visitors
        // would otherwise each search the posts table for the same day. The others see no quiz
        // for the second it takes, then the one that was made.
        $store = $this->cache instanceof Repository ? $this->cache->getStore() : null;
        $lock = $store instanceof LockProvider ? $store->lock('ernestdefoe-who-said-it.make.'.$date, 120) : null;
        if ($lock && ! $lock->get()) {
            $this->busy = true;

            return null;
        }

        try {
            return $this->make($date);
        } finally {
            $lock?->release();
        }
    }

    private function make(string $date): ?Day
    {
        $day = Day::query()->where('day', $date)->first();
        if ($day) {
            return $day;
        }

        $picks = $this->pick();
        if (count($picks) < self::MIN_QUESTIONS) {
            // Don't search the whole forum again on every page view.
            $this->cache->put($this->noneKey($date), 1, self::NONE_TTL);

            return null;
        }

        try {
            $made = $this->db->transaction(function () use ($date, $picks) {
                $day = new Day();
                $day->day = $date;
                $day->question_count = count($picks);
                $day->created_at = Carbon::now();
                $day->save();

                foreach ($picks as $i => $pick) {
                    $question = new Question();
                    $question->day_id = $day->id;
                    $question->position = $i + 1;
                    $question->post_id = $pick['post']->id;
                    $question->discussion_id = $pick['post']->discussion_id;
                    $question->author_id = $pick['post']->user_id;
                    $question->excerpt = $pick['excerpt'];
                    $question->choices = implode(',', $pick['choices']);
                    $question->created_at = Carbon::now();
                    $question->save();
                }

                return $day;
            });
            $this->cache->forget(ForumState::key($date));

            return $made;
        } catch (QueryException) {
            // Someone else made the day first: theirs stands.
            return Day::query()->where('day', $date)->first();
        }
    }

    private function noneKey(string $date): string
    {
        return 'ernestdefoe-who-said-it.none.'.$date;
    }

    /**
     * Up to five quotes from five different authors, each with its four choices.
     *
     * @return list<array{post: CommentPost, excerpt: string, choices: list<int>}>
     */
    public function pick(): array
    {
        $random = new Randomizer();
        $minLength = $this->settings->int(Settings::MIN_LENGTH, 20, 200);
        $picks = [];
        $authors = [];
        $tried = [];

        foreach ($this->candidates($random) as $post) {
            if (count($picks) >= self::QUESTIONS) {
                break;
            }
            if (isset($authors[$post->user_id]) || isset($tried[$post->id]) || ! $post instanceof CommentPost) {
                continue;
            }
            $tried[$post->id] = true;

            $author = $post->user;
            if (! $author) {
                continue;
            }

            $excerpt = Excerpt::fromHtml($post->formatContent(), [$author->username, $author->display_name], Excerpt::MAX, (string) $post->getRawOriginal('content'));
            if (mb_strlen($excerpt) < $minLength || ! Excerpt::reads($excerpt)) {
                continue;
            }

            $others = $this->distractors($author, $post, $random);
            if (count($others) < 3) {
                continue;
            }

            $authors[$author->id] = true;
            $picks[] = [
                'post' => $post,
                'excerpt' => $excerpt,
                'choices' => array_values($random->shuffleArray([$author->id, ...$others])),
            ];
        }

        return $picks;
    }

    /**
     * Random eligible posts, a few at a time from random points in the id
     * range: an index walk, never ORDER BY RAND() over the posts table.
     *
     * @return iterable<Post>
     */
    private function candidates(Randomizer $random): iterable
    {
        $max = (int) Post::query()->max('id');
        if ($max < 1) {
            return;
        }

        // A forum where a few members write most posts needs more walks to find five authors;
        // the caller stops pulling as soon as it has them.
        for ($round = 0; $round < 20; $round++) {
            $from = $random->getInt(1, $max);
            yield from $this->eligible()->with('user')->where('posts.id', '>=', $from)->orderBy('posts.id')->limit(10)->get();
        }

        // A small or sparse forum: take what there is, newest first.
        yield from $this->eligible()->with('user')->orderByDesc('posts.id')->limit(60)->get()->shuffle();
    }

    /** @return Builder<Post> */
    public function eligible(): Builder
    {
        $minAge = $this->settings->int(Settings::MIN_AGE, 0, 3650);
        $minLength = $this->settings->int(Settings::MIN_LENGTH, 20, 200);
        $repeat = $this->settings->int(Settings::REPEAT_DAYS, 0, 36500);

        $query = Post::query()
            ->whereVisibleTo(new Guest())
            ->where('posts.type', 'comment')
            ->where('posts.is_private', false)
            ->whereNull('posts.hidden_at')
            ->whereNotNull('posts.user_id')
            ->where('posts.created_at', '<=', Carbon::now()->subDays($minAge))
            // A cheap first cut on the stored markup; the cleaned excerpt is measured again.
            ->whereRaw('LENGTH('.$this->db->getQueryGrammar()->wrap('posts.content').') >= ?', [$minLength])
            ->tap(fn ($q) => $this->exclusions->leaveOut($q, 'posts.user_id'))
            ->whereNotIn('posts.discussion_id', fn ($q) => $q->select('id')->from('discussions')->where('is_private', true));

        if ($repeat > 0) {
            $query->whereNotIn('posts.id', fn ($q) => $q->select('post_id')->from('who_said_it_questions')
                ->whereNotNull('post_id')->where('created_at', '>=', Carbon::now()->subDays($repeat)));
        }

        $tags = $this->settings->tags();
        if ($tags && $this->extensions->isEnabled('flarum-tags')) {
            $query->whereIn('posts.discussion_id', fn ($q) => $q->select('discussion_id')->from('discussion_tag')->whereIn('tag_id', $tags));
        }

        return $query;
    }

    /**
     * Three other members who look like the author, so the faces don't give
     * the answer away: they had joined by the time the post was written, have
     * about as many posts (the dozen closest), and are as active lately as
     * the author is: recent members beside a recent author, members gone
     * quiet beside one who has. When too few match, activity is ignored.
     *
     * @return list<int>
     */
    private function distractors(User $author, CommentPost $post, Randomizer $random): array
    {
        $count = $this->db->getQueryGrammar()->wrap('comment_count');
        $base = fn () => User::query()
            ->where('id', '!=', $author->id)
            ->tap(fn ($q) => $this->exclusions->leaveOut($q, 'id'))
            ->where('comment_count', '>', 0)
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhere('joined_at', '<=', $post->created_at))
            // Distance in post count. 🚨 comment_count is UNSIGNED on MySQL: `count - n` below zero is an
            // out-of-range error, not a negative number, so subtract the smaller from the larger.
            ->orderByRaw("CASE WHEN $count >= ? THEN $count - ? ELSE ? - $count END", array_fill(0, 3, (int) $author->comment_count))
            ->limit(12);

        $since = Carbon::now()->subDays($this->settings->int(Settings::ACTIVE_DAYS, 1, 3650));
        $authorActive = $author->last_seen_at && $author->last_seen_at->gte($since);
        $alike = $base()
            ->when($authorActive, fn ($q) => $q->where('last_seen_at', '>=', $since), fn ($q) => $q->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $since)))
            ->pluck('id')->all();
        $ids = count($alike) >= 3 ? $alike : $base()->pluck('id')->all();

        return array_slice(array_map('intval', $random->shuffleArray($ids)), 0, 3);
    }
}
