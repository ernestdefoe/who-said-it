<?php

namespace ErnestDefoe\WhoSaidIt\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\WhoSaidIt\Answer;
use ErnestDefoe\WhoSaidIt\Day;
use ErnestDefoe\WhoSaidIt\Generator;
use ErnestDefoe\WhoSaidIt\Job\SendReminders;
use ErnestDefoe\WhoSaidIt\Player;
use ErnestDefoe\WhoSaidIt\Question;
use ErnestDefoe\WhoSaidIt\Quiz;
use ErnestDefoe\WhoSaidIt\Score;
use ErnestDefoe\WhoSaidIt\Scoring;
use Flarum\Discussion\Discussion;
use Flarum\Notification\Notification;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class QuizTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** Authors 3–9 each wrote one long public post; 2 is the player. */
    private const AUTHORS = [3, 4, 5, 6, 7, 8, 9];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-who-said-it');

        $old = Carbon::now()->subDays(30);
        $users = [$this->normalUser()];
        $discussions = [];
        $posts = [];
        foreach (self::AUTHORS as $id) {
            $users[] = ['id' => $id, 'username' => "author$id", 'email' => "a$id@machine.local", 'is_email_confirmed' => 1, 'comment_count' => 10 + $id, 'last_seen_at' => Carbon::now()];
            $discussions[] = ['id' => $id, 'title' => "Thread $id", 'created_at' => $old, 'user_id' => $id, 'first_post_id' => $id, 'comment_count' => 1, 'last_post_number' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'user_id' => $id, 'type' => 'comment', 'number' => 1, 'created_at' => $old,
                'content' => "<t><p>Quote number $id: honestly the best part of the season was watching the bench argue about the playbook for a full hour.</p></t>"];
        }

        // Things that must never be quoted.
        $users[] = ['id' => 20, 'username' => 'secretive', 'email' => 's@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 12, 'last_seen_at' => Carbon::now()];
        $long = 'This is a long enough secret sentence that would otherwise be a perfectly good quote for the quiz of the day.';
        $discussions[] = ['id' => 20, 'title' => 'Private', 'created_at' => $old, 'user_id' => 20, 'first_post_id' => 20, 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => 1];
        $discussions[] = ['id' => 21, 'title' => 'Staff only', 'created_at' => $old, 'user_id' => 20, 'first_post_id' => 21, 'comment_count' => 1, 'last_post_number' => 1];
        $discussions[] = ['id' => 22, 'title' => 'Hidden post', 'created_at' => $old, 'user_id' => 20, 'first_post_id' => 22, 'comment_count' => 1, 'last_post_number' => 1];
        $discussions[] = ['id' => 23, 'title' => 'Too new', 'created_at' => Carbon::now(), 'user_id' => 20, 'first_post_id' => 23, 'comment_count' => 1, 'last_post_number' => 1];
        $discussions[] = ['id' => 24, 'title' => 'Short', 'created_at' => $old, 'user_id' => 20, 'first_post_id' => 24, 'comment_count' => 1, 'last_post_number' => 1];
        $posts[] = ['id' => 20, 'discussion_id' => 20, 'user_id' => 20, 'type' => 'comment', 'number' => 1, 'created_at' => $old, 'content' => "<t><p>PRIVATE $long</p></t>", 'is_private' => 1];
        $posts[] = ['id' => 21, 'discussion_id' => 21, 'user_id' => 20, 'type' => 'comment', 'number' => 1, 'created_at' => $old, 'content' => "<t><p>STAFF $long</p></t>"];
        $posts[] = ['id' => 22, 'discussion_id' => 22, 'user_id' => 20, 'type' => 'comment', 'number' => 1, 'created_at' => $old, 'content' => "<t><p>HIDDEN $long</p></t>", 'hidden_at' => $old];
        $posts[] = ['id' => 23, 'discussion_id' => 23, 'user_id' => 20, 'type' => 'comment', 'number' => 1, 'created_at' => Carbon::now(), 'content' => "<t><p>FRESH $long</p></t>"];
        $posts[] = ['id' => 24, 'discussion_id' => 24, 'user_id' => 20, 'type' => 'comment', 'number' => 1, 'created_at' => $old, 'content' => '<t><p>lol same</p></t>'];

        $this->prepareDatabase([
            User::class => $users,
            Discussion::class => $discussions,
            Post::class => $posts,
            'tags' => [
                ['id' => 1, 'name' => 'Staff', 'slug' => 'staff', 'is_restricted' => 1, 'position' => 0],
                ['id' => 2, 'name' => 'General', 'slug' => 'general', 'is_restricted' => 0, 'position' => 1],
            ],
            'discussion_tag' => [['discussion_id' => 21, 'tag_id' => 1]],
        ]);
    }

    /**
     * Making a day walks the posts table from a few random points, one query
     * each: once a day, in the scheduler (or the first page view of the day).
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['posts"."id" asc limit ?', 'posts`.`id` asc limit ?'];
    }

    /** @return array{int, array<string, mixed>|null, string} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $response = $this->send($this->request($method, '/api/who-said-it'.$path, $options));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    private function make(): Day
    {
        $day = $this->app()->getContainer()->make(Generator::class)->today();
        $this->assertNotNull($day);

        return $day;
    }

    /** @return array{int, array<string, mixed>, string} */
    private function next(int $as = 2): array
    {
        $result = $this->api('POST', '/next', $as);
        $this->assertSame(200, $result[0], $result[2]);

        return $result;
    }

    #[Test]
    public function a_day_is_five_public_quotes_and_making_it_twice_changes_nothing()
    {
        $day = $this->make();
        $again = $this->app()->getContainer()->make(Generator::class)->forDate($day->day, true);

        $this->assertSame($day->id, $again->id);
        $this->assertSame(1, Day::query()->count());
        $questions = Question::query()->orderBy('position')->get();
        $this->assertCount(5, $questions);
        $this->assertCount(5, $questions->pluck('author_id')->unique(), 'one quote per author');

        foreach ($questions as $q) {
            $this->assertContains($q->author_id, self::AUTHORS);
            $this->assertCount(4, $q->choiceIds());
            $this->assertContains($q->author_id, $q->choiceIds());
            $this->assertCount(4, array_unique($q->choiceIds()));
        }
    }

    #[Test]
    public function private_restricted_hidden_new_short_and_opted_out_posts_are_never_picked()
    {
        // Leave only the forbidden posts and one opted-out author.
        $this->app();
        Post::query()->whereIn('id', [4, 5, 6, 7, 8, 9])->delete();
        Player::query()->insert(['user_id' => 3, 'opted_out' => true]);

        $generator = $this->app()->getContainer()->make(Generator::class);
        $ids = $generator->eligible()->pluck('posts.id')->all();
        $this->assertSame([], $ids);
        $this->assertNull($generator->today(), 'not enough quotes: no quiz');
    }

    #[Test]
    public function only_one_request_makes_a_day_at_a_time()
    {
        $this->app();
        $date = Carbon::now()->toDateString();
        $lock = $this->app()->getContainer()->make('cache.store')->getStore()->lock('ernestdefoe-who-said-it.make.'.$date, 120);
        $this->assertTrue($lock->get());

        $generator = $this->app()->getContainer()->make(Generator::class);
        $this->assertNull($generator->forDate($date), 'another request is making it');
        $this->assertSame(0, Day::query()->count());

        $lock->release();
        $this->assertNotNull($generator->forDate($date));
    }

    #[Test]
    public function no_quote_is_reused_within_the_repeat_window()
    {
        $day = $this->make();
        $used = Question::query()->pluck('post_id')->all();

        $eligible = $this->app()->getContainer()->make(Generator::class)->eligible()->pluck('posts.id')->all();
        $this->assertSame([], array_intersect($used, $eligible));
        $this->assertSame(5, $day->question_count);
    }

    /** 🔒 The core promise: nothing before an answer tells who wrote it. */
    #[Test]
    public function the_answer_never_reaches_the_browser_before_the_member_answers()
    {
        $day = $this->make();
        $first = Question::query()->where('day_id', $day->id)->where('position', 1)->first();
        $post = Post::query()->find($first->post_id);

        [, $page, $pageRaw] = $this->api('GET', '', 2);
        $this->assertNull($page['current']);
        $this->assertSame([], $page['played']);
        $this->assertStringNotContainsString($first->excerpt, $pageRaw, 'unreached quotes are not on the page at all');

        [, $next, $nextRaw] = $this->next();
        $current = $next['current'];
        $this->assertSame(['id', 'position', 'excerpt', 'choices', 'elapsedMs', 'limitMs'], array_keys($current));
        $this->assertSame($first->id, $current['id']);
        $this->assertStringNotContainsString('"author":', $nextRaw);
        $this->assertStringNotContainsString('/d/', $nextRaw);
        $this->assertStringNotContainsString('Thread ', $nextRaw);
        $this->assertStringNotContainsString('"post', $nextRaw);
        $this->assertStringNotContainsString('discussion', $nextRaw);
        // The four faces come back as one sorted list, not in "author first" order.
        $this->assertSame($first->choiceIds(), $current['choices']);
        $this->assertSame(array_column($next['users'], 'id'), array_values(array_intersect(array_column($next['users'], 'id'), $first->choiceIds())));

        // Reloading the page mid-question shows the same quote, still without its answer.
        [, $reload, $reloadRaw] = $this->api('GET', '', 2);
        $this->assertSame(array_keys($current), array_keys($reload['current']));
        $this->assertStringNotContainsString('"author":'.$first->author_id, $reloadRaw);
        $this->assertSame([], $reload['played']);

        // A guest or another member sees nothing of it either.
        [, , $guestRaw] = $this->api('GET', '', null);
        $this->assertStringNotContainsString($first->excerpt, $guestRaw);
        $this->assertStringNotContainsString((string) $post->number.'"', '');

        // After answering, the answer comes with the reveal.
        [$status, $answered] = $this->api('POST', '/answer', 2, ['question' => $first->id, 'choice' => $first->author_id]);
        $this->assertSame(200, $status);
        $this->assertSame($first->author_id, $answered['reveal']['author']);
        $this->assertStringContainsString('/d/'.$first->discussion_id, $answered['reveal']['post']['url']);
    }

    #[Test]
    public function answers_are_checked_on_the_server_and_count_once()
    {
        $this->make();
        $current = $this->next()[1]['current'];
        $q = Question::query()->find($current['id']);
        $wrong = current(array_diff($q->choiceIds(), [$q->author_id]));

        // A pick that isn't one of the four.
        $this->assertSame(422, $this->api('POST', '/answer', 2, ['question' => $q->id, 'choice' => 999])[0]);

        [$status, $body] = $this->api('POST', '/answer', 2, ['question' => $q->id, 'choice' => $wrong]);
        $this->assertSame(200, $status);
        $this->assertFalse($body['reveal']['correct']);
        $this->assertSame(0, $body['reveal']['points']);

        // Trying again with the right one changes nothing.
        [$status, $body] = $this->api('POST', '/answer', 2, ['question' => $q->id, 'choice' => $q->author_id]);
        $this->assertSame(200, $status);
        $this->assertFalse($body['reveal']['correct']);
        $score = Score::query()->where('user_id', 2)->first();
        $this->assertSame(0, $score->score);
        $this->assertSame(1, $score->answered);
        $this->assertSame(Scoring::WRONG, $score->grid[0]);
    }

    #[Test]
    public function a_quote_cannot_be_answered_before_it_is_shown_or_after_its_day()
    {
        $day = $this->make();
        $later = Question::query()->where('day_id', $day->id)->where('position', 3)->first();

        // Skipping ahead: never shown, so no answer (and no peek at its author).
        [$status, $body, $raw] = $this->api('POST', '/answer', 2, ['question' => $later->id, 'choice' => $later->author_id]);
        $this->assertSame(422, $status);
        $this->assertSame('wsi_not_shown', $body['errors'][0]['code']);
        $this->assertStringNotContainsString('"author":', $raw);

        // Guests can't play at all.
        $this->assertNotSame(200, $this->api('POST', '/next', null)[0]);
        $this->assertNotSame(200, $this->api('POST', '/answer', null, ['question' => $later->id, 'choice' => 3])[0]);
        $this->assertSame(0, Answer::query()->count());

        // Yesterday's quiz is over: its answers are public, so it can't be scored.
        $this->next();
        $first = Question::query()->where('day_id', $day->id)->where('position', 1)->first();
        Day::query()->whereKey($day->id)->update(['day' => Carbon::now()->subDay()->toDateString()]);
        [$status, $body] = $this->api('POST', '/answer', 2, ['question' => $first->id, 'choice' => $first->author_id]);
        $this->assertSame(422, $status);
        $this->assertSame('wsi_closed', $body['errors'][0]['code']);
    }

    #[Test]
    public function the_speed_bonus_is_timed_on_the_server()
    {
        $this->make();
        $start = Carbon::now();
        Carbon::setTestNow($start);
        $q = Question::query()->find($this->next()[1]['current']['id']);

        // The browser can't claim it was quick: ten seconds passed on the server.
        Carbon::setTestNow($start->copy()->addSeconds(10));
        $reveal = $this->api('POST', '/answer', 2, ['question' => $q->id, 'choice' => $q->author_id, 'ms' => 1])[1]['reveal'];
        $this->assertTrue($reveal['correct']);
        $this->assertSame(Scoring::BASE + Scoring::bonus(10000), $reveal['points']);
        $this->assertSame(10000, $reveal['ms']);

        // A reload doesn't restart the clock either.
        $q2 = Question::query()->find($this->next()[1]['current']['id']);
        Carbon::setTestNow($start->copy()->addSeconds(40));
        $this->assertSame($q2->id, $this->next()[1]['current']['id']);
        $reveal = $this->api('POST', '/answer', 2, ['question' => $q2->id, 'choice' => $q2->author_id])[1]['reveal'];
        $this->assertSame(Scoring::BASE, $reveal['points']);
        Carbon::setTestNow();
    }

    #[Test]
    public function finishing_a_day_scores_it_and_builds_the_streak()
    {
        $day = $this->make();
        $this->playAll(2, true);

        $score = Score::query()->where('user_id', 2)->first();
        $this->assertNotNull($score->finished_at);
        $this->assertSame(5, $score->correct);
        $this->assertSame(5 * (Scoring::BASE + Scoring::MAX_BONUS), $score->score);
        $this->assertSame(str_repeat(Scoring::FAST, 5), $score->grid);

        $player = Player::query()->find(2);
        $this->assertSame(1, $player->streak);
        $this->assertSame(750, $player->total_score);

        // No more quotes today.
        [, $next] = $this->next();
        $this->assertNull($next['current']);
        $this->assertTrue($next['me']['finished']);
        $this->assertSame(1, $next['me']['rank']);

        // Today's board has them; the page shows all five answers.
        [, $page] = $this->api('GET', '', 3);
        $this->assertSame(2, $page['day']['top'][0]['user']);
        [, $mine] = $this->api('GET', '', 2);
        $this->assertCount(5, $mine['played']);

        // Tomorrow: the streak goes on. Skip a day: it starts over.
        Carbon::setTestNow(Carbon::now()->addDay());
        Question::query()->update(['created_at' => Carbon::now()->subYears(2)]);
        $this->make();
        $this->playAll(2, false);
        $this->assertSame(2, Player::query()->find(2)->streak);

        Carbon::setTestNow(Carbon::now()->addDays(2));
        Post::query()->update(['created_at' => Carbon::now()->subDays(30)]);
        Question::query()->update(['created_at' => Carbon::now()->subYears(2)]);
        $this->make();
        $this->playAll(2, false);
        $player = Player::query()->find(2);
        $this->assertSame(1, $player->streak);
        $this->assertSame(2, $player->best_streak);
        $this->assertSame(3, $player->quizzes);
        Carbon::setTestNow();
    }

    private function playAll(int $as, bool $right): void
    {
        for ($i = 0; $i < 5; $i++) {
            $current = $this->next($as)[1]['current'];
            if (! $current) {
                return;
            }
            $q = Question::query()->find($current['id']);
            $pick = $right ? $q->author_id : current(array_diff($q->choiceIds(), [$q->author_id]));
            $this->assertSame(200, $this->api('POST', '/answer', $as, ['question' => $q->id, 'choice' => $pick])[0]);
        }
    }

    #[Test]
    public function a_quote_whose_post_goes_private_is_withdrawn()
    {
        $day = $this->make();
        $second = Question::query()->where('day_id', $day->id)->where('position', 2)->first();
        Post::query()->whereKey($second->post_id)->update(['hidden_at' => Carbon::now()]);

        $this->playAll(2, true);
        $score = Score::query()->where('user_id', 2)->first();
        $this->assertNotNull($score->finished_at);
        $this->assertSame(4, $score->answered);
        $this->assertSame(Scoring::VOID, $score->grid[1]);
        $this->assertSame(0, Answer::query()->where('question_id', $second->id)->count(), 'never shown');
    }

    #[Test]
    public function members_can_opt_out_of_being_quoted_and_into_reminders()
    {
        [$status, $body] = $this->api('POST', '/me', 3, ['optedOut' => true, 'remind' => true]);
        $this->assertSame(200, $status);
        $this->assertTrue($body['optedOut']);

        $this->make();
        $this->assertSame(0, Question::query()->where('author_id', 3)->count());
        foreach (Question::query()->get() as $q) {
            $this->assertNotContains(3, $q->choiceIds(), 'opted out = not a choice either');
        }

        // The forum payload carries the member's own state for the card and settings.
        $response = $this->send($this->request('GET', '/api', ['authenticatedAs' => 3]));
        $forum = json_decode((string) $response->getBody(), true)['data']['attributes']['whoSaidIt'];
        $this->assertTrue($forum['me']['optedOut']);
        $this->assertTrue($forum['me']['remind']);
        $this->assertSame(5, $forum['count']);
    }

    #[Test]
    public function members_of_a_never_quote_group_are_neither_quoted_nor_a_choice()
    {
        $this->setting('ernestdefoe-who-said-it.never_quote_groups', json_encode([4]));
        $this->prepareDatabase(['group_user' => [['user_id' => 3, 'group_id' => 4], ['user_id' => 5, 'group_id' => 4]]]);

        $this->make();
        $this->assertSame(0, Question::query()->whereIn('author_id', [3, 5])->count());
        foreach (Question::query()->get() as $q) {
            $this->assertSame([], array_intersect([3, 5], $q->choiceIds()), 'never shown as a choice either');
        }
        $this->assertSame(5, Question::query()->count(), 'the other five authors still fill the day');
    }

    #[Test]
    public function deleting_a_member_never_touches_flarum_gdpr()
    {
        $day = $this->make();
        $q = Question::query()->where('day_id', $day->id)->first();
        $response = $this->send($this->request('DELETE', '/api/users/'.$q->author_id, ['authenticatedAs' => 1]));

        $this->assertSame(204, $response->getStatusCode());
        // The GDPR data type extends flarum/gdpr's class: loading it on a forum without that package is a 500.
        $this->assertFalse(class_exists(\ErnestDefoe\WhoSaidIt\Gdpr\WhoSaidItData::class, false));
    }

    #[Test]
    public function opting_out_takes_a_member_out_of_todays_and_yesterdays_quiz_at_once()
    {
        $day = $this->make();
        [$q1, $q2] = Question::query()->where('day_id', $day->id)->orderBy('position')->take(2)->get()->all();
        // Played the first quote already; its author and one of the second quote's faces then opt out.
        $this->next();
        $this->api('POST', '/answer', 2, ['question' => $q1->id, 'choice' => $q1->author_id]);
        $face = current(array_diff($q2->choiceIds(), [$q2->author_id, $q1->author_id]));
        $this->assertSame(200, $this->api('POST', '/me', $q1->author_id, ['optedOut' => true])[0]);
        $this->assertSame(200, $this->api('POST', '/me', $face, ['optedOut' => true])[0]);

        [, $next, $raw] = $this->next();
        $this->assertSame($q2->id, $next['current']['id']);
        $this->assertContains(0, $next['current']['choices'], 'the opted-out face is a blank');
        $this->assertNotContains($face, $next['current']['choices']);

        [, $page, $pageRaw] = $this->api('GET', '', 2);
        $this->assertTrue($page['played'][0]['withdrawn']);
        $this->assertSame('', $page['played'][0]['excerpt']);
        $this->assertSame([], $page['played'][0]['choices']);
        $this->assertStringNotContainsString($q1->excerpt, $pageRaw);

        // Yesterday's answers, already cached, are drawn again without them.
        Day::query()->whereKey($day->id)->update(['day' => Carbon::now()->subDay()->toDateString()]);
        $this->api('POST', '/me', $q1->author_id, ['optedOut' => false]);
        [, $before] = $this->api('GET', '', null);
        $this->assertFalse($before['yesterday']['questions'][0]['withdrawn']);
        $this->api('POST', '/me', $q1->author_id, ['optedOut' => true]);
        [, $after, $afterRaw] = $this->api('GET', '', null);
        $this->assertTrue($after['yesterday']['questions'][0]['withdrawn']);
        $this->assertStringNotContainsString($q1->excerpt, $afterRaw);
    }

    #[Test]
    public function a_never_quote_group_set_later_withdraws_their_quotes_too()
    {
        $day = $this->make();
        $q = Question::query()->where('day_id', $day->id)->orderBy('position')->first();
        $this->database()->table('group_user')->insert(['user_id' => $q->author_id, 'group_id' => 4]);
        $response = $this->send($this->request('POST', '/api/settings', ['authenticatedAs' => 1, 'json' => ['ernestdefoe-who-said-it.never_quote_groups' => json_encode([4])]]));
        $this->assertSame(204, $response->getStatusCode());

        $this->assertNotSame($q->id, $this->next()[1]['current']['id'], 'their quote is skipped');
    }

    #[Test]
    public function a_suspended_member_is_neither_quoted_nor_a_choice()
    {
        $this->extension('flarum-tags', 'flarum-suspend', 'ernestdefoe-who-said-it');
        $this->app();
        User::query()->whereIn('id', [3, 5])->update(['suspended_until' => Carbon::now()->addWeek()]);

        $this->make();
        $this->assertSame(0, Question::query()->whereIn('author_id', [3, 5])->count());
        foreach (Question::query()->get() as $q) {
            $this->assertSame([], array_intersect([3, 5], $q->choiceIds()));
        }
    }

    #[Test]
    public function an_edit_that_takes_the_words_out_withdraws_the_quote()
    {
        $day = $this->make();
        $q = Question::query()->where('day_id', $day->id)->orderBy('position')->first();
        Post::query()->whereKey($q->post_id)->update(['content' => '<t><p>[edited: removed]</p></t>']);

        $this->assertNotSame($q->id, $this->next()[1]['current']['id']);

        // Formatting the same words differently is not a change.
        $other = Question::query()->where('day_id', $day->id)->where('position', 3)->first();
        $text = Post::query()->whereKey($other->post_id)->value('content');
        Post::query()->whereKey($other->post_id)->update(['content' => str_replace('honestly', '<STRONG><s>**</s>honestly<e>**</e></STRONG>', $text)]);
        $this->assertArrayHasKey($other->id, $this->app()->getContainer()->make(Quiz::class)->live(Question::query()->whereKey($other->id)->get()));
    }

    #[Test]
    public function the_other_faces_look_like_the_author()
    {
        $this->prepareDatabase([User::class => [
            ['id' => 21, 'username' => 'quiet1', 'email' => 'q1@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 12, 'last_seen_at' => Carbon::now()->subYear(), 'joined_at' => Carbon::now()->subYears(2)],
            ['id' => 22, 'username' => 'quiet2', 'email' => 'q2@machine.local', 'is_email_confirmed' => 0, 'comment_count' => 12, 'last_seen_at' => Carbon::now()->subYear(), 'joined_at' => Carbon::now()->subYears(2)],
            ['id' => 23, 'username' => 'newcomer', 'email' => 'n@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 14, 'last_seen_at' => Carbon::now(), 'joined_at' => Carbon::now()->subDay()],
        ]]);
        $this->app();
        // Author 3 (and 20) went quiet a year ago, like 21 and 22; the rest are around today.
        User::query()->whereIn('id', [3, 20])->update(['last_seen_at' => Carbon::now()->subYear()]);

        $this->make();
        foreach (Question::query()->get() as $q) {
            $others = array_values(array_diff($q->choiceIds(), [$q->author_id]));
            $this->assertNotContains(23, $others, 'joined after the post was written');
            if ($q->author_id === 3) {
                $this->assertEqualsCanonicalizing([20, 21, 22], $others, 'quiet beside quiet, confirmed or not');
            } else {
                $this->assertSame([], array_intersect([3, 20, 21, 22], $others), 'active beside active');
            }
        }
    }

    #[Test]
    public function a_day_left_unfinished_still_counts_and_keeps_the_streak()
    {
        $day = $this->make();
        for ($i = 0; $i < 2; $i++) {
            $q = Question::query()->find($this->next()[1]['current']['id']);
            $this->api('POST', '/answer', 2, ['question' => $q->id, 'choice' => $q->author_id]);
        }
        $this->next(3); // opened, answered nothing
        Day::query()->whereKey($day->id)->update(['day' => Carbon::now()->subDay()->toDateString()]);

        $this->app()->getContainer()->make(Quiz::class)->settle();

        $score = Score::query()->where('user_id', 2)->first();
        $this->assertNotNull($score->finished_at);
        $this->assertSame(2, $score->answered);
        $player = Player::query()->find(2);
        $this->assertSame(1, $player->streak);
        $this->assertSame($score->score, $player->total_score);
        $this->assertSame(0, Score::query()->where('user_id', 3)->count(), 'a day with nothing answered is dropped');
    }

    #[Test]
    public function while_another_request_makes_the_quiz_it_is_on_its_way()
    {
        $this->app();
        $lock = $this->app()->getContainer()->make('cache.store')->getStore()->lock('ernestdefoe-who-said-it.make.'.Carbon::now()->toDateString(), 120);
        $this->assertTrue($lock->get());

        [, $page] = $this->api('GET', '', 2);
        $this->assertNull($page['day']);
        $this->assertTrue($page['pending']);
        [$status, $body] = $this->api('POST', '/next', 2);
        $this->assertSame(503, $status);
        $this->assertSame('wsi_making', $body['errors'][0]['code']);
        $lock->release();
    }

    #[Test]
    public function reminders_go_once_to_members_who_asked_and_have_not_played()
    {
        $this->app();
        Player::query()->insert([['user_id' => 2, 'remind' => true], ['user_id' => 4, 'remind' => true], ['user_id' => 5, 'remind' => false]]);
        $day = $this->make();
        $this->next(4); // 4 has started today's quiz already

        $job = new SendReminders($day->id);
        $this->app()->getContainer()->call([$job, 'handle']);
        $this->app()->getContainer()->call([$job, 'handle']);

        $sent = Notification::query()->where('type', 'whoSaidItReminder')->pluck('user_id')->all();
        $this->assertSame([2], $sent);

        // And it shows in the member's list (its subject is a User, which has an API resource).
        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('whoSaidItReminder', (string) $response->getBody());
    }

    #[Test]
    public function yesterdays_answers_are_public_and_today_is_not()
    {
        $day = $this->make();
        $this->playAll(2, true);
        Day::query()->whereKey($day->id)->update(['day' => Carbon::now()->subDay()->toDateString()]);

        [$status, $page] = $this->api('GET', '', null);
        $this->assertSame(200, $status);
        $this->assertFalse($page['canPlay']);
        $this->assertCount(5, $page['yesterday']['questions']);
        $this->assertSame(100, $page['yesterday']['questions'][0]['percent']);
        $this->assertContains($page['yesterday']['questions'][0]['author'], self::AUTHORS);
        $this->assertNotEmpty($page['allTime']);
    }

    #[Test]
    public function deleting_a_member_takes_their_quoted_words_with_them()
    {
        $day = $this->make();
        $q = Question::query()->where('day_id', $day->id)->first();
        $response = $this->send($this->request('DELETE', '/api/users/'.$q->author_id, ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());

        $q->refresh();
        $this->assertSame('', $q->excerpt);
        $this->assertNull($q->author_id);
    }
}
