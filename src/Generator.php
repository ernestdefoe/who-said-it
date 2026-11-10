<?php

namespace ErnestDefoe\WhoSaidIt;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
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
    /** Fewer than this and there's no quiz today (a tiny or brand-new forum). */
    public const MIN_QUESTIONS = 3;
    private const NONE_TTL = 1800;

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly ExtensionManager $extensions,
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
        $optedOut = $this->optedOut();
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

            $excerpt = Excerpt::fromHtml($post->formatContent(), [$author->username, $author->display_name]);
            if (mb_strlen($excerpt) < $minLength) {
                continue;
            }

            $others = $this->distractors($author, $optedOut, $random);
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

        for ($round = 0; $round < 8; $round++) {
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
            ->whereNotIn('posts.user_id', Player::query()->select('user_id')->where('opted_out', true))
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

    /** @return list<int> */
    private function optedOut(): array
    {
        return Player::query()->where('opted_out', true)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Three other members with about as many posts as the author, picked
     * from the dozen closest. Active members first; anyone with posts when
     * the forum is quiet.
     *
     * @param list<int> $optedOut
     * @return list<int>
     */
    private function distractors(User $author, array $optedOut, Randomizer $random): array
    {
        $base = fn () => User::query()
            ->where('id', '!=', $author->id)
            ->whereNotIn('id', $optedOut)
            ->where('is_email_confirmed', true)
            ->where('comment_count', '>', 0)
            ->orderByRaw('ABS('.$this->db->getQueryGrammar()->wrap('comment_count').' - ?)', [(int) $author->comment_count])
            ->limit(12);

        $active = $base()->where('last_seen_at', '>=', Carbon::now()->subDays($this->settings->int(Settings::ACTIVE_DAYS, 1, 3650)))->pluck('id')->all();
        $ids = count($active) >= 3 ? $active : $base()->pluck('id')->all();

        return array_slice(array_map('intval', $random->shuffleArray($ids)), 0, 3);
    }
}
