# Who Said It?: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **The answer never reaches a browser before the member answers.** A quote is served by `Quiz::served()` (id, position, excerpt, choices, elapsedMs, limitMs: nothing else). `author_id`, `post_id` and `discussion_id` leave the server only through `Quiz::reveal()`, called for a quote the member has answered or for a past day. Quotes the member hasn't reached aren't in the page payload at all. `QuizTest::the_answer_never_reaches_the_browser_before_the_member_answers` proves it; keep it passing and extend it when the payload grows.
- **The server checks and times every answer.** `/next` stamps `shown_ms` when a quote is first served (a reload keeps the clock running); `/answer` takes only the question id and the picked user id. Answers are recorded by one guarded UPDATE (`answered_at IS NULL`), so a double tap can't score twice. Only today's quiz can be answered, only a quote already shown, only one of its four choices.
- **Quotes come only from posts a GUEST can read**: `Post::whereVisibleTo(new Guest())` (core + every extension's scopes: tags, Byobu, approval, hidden), plus explicit `is_private` checks. A quote whose post later stops being guest-visible is withdrawn (`Quiz::live()`), skipped for players and blanked in yesterday's list.
- **Generating is idempotent**: the unique `day` row and its questions are written in one transaction; a racing run reads back the winner. A day the forum can't fill caches "none" for 30 minutes so page views don't search the posts table each time. Random posts come from index walks at random ids (never `ORDER BY RAND()`).
- 🚨 `comment_count` is UNSIGNED on MySQL: never `count - n` in SQL (out-of-range error); the distractor order uses a CASE.
- **The forum payload costs one query for a member** (`ForumState`: users ⟕ players ⟕ today's score) and none for guests (the day is cached). It never generates.
- **Reminders are opt-in** (`players.remind`), sent once per day (`Day.reminded_at` claimed by one guarded UPDATE, then a queued job). The notification subject is the member (Users have an API resource; a subject without one hides or breaks notification lists).
- **Opt-out** (`players.opted_out`) keeps a member out of quotes AND choices from the next quiz on. Deleting a member (`Deleting`) or a GDPR erase blanks their quoted words.
- Never name a translation parameter `user` (Flarum's translator treats it as a User model).
- Themes recolour links: every text colour inside our `<a>` elements is set under `html:not(#x-wsi)`.
- Mithril: siblings are all keyed or none; the quote is keyed alone inside `.WsiQuote-wrap` so its entrance replays.
- The page is a lazy chunk (`WhoSaidItPage`, which also loads core's lazy `PageStructure`/`IndexSidebar`); forum.js wires the route, sidebar link + card, two settings switches, widgets (Bespoke + opt-in FoF) and the notification.

## Testing

- `composer test:setup && composer test` (integration; SQLite and prefixed MySQL both pass), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- The showcase seed is `~/github/showcase/seeds/98-who-said-it.php` (makes and plays five past days + today through Generator/Quiz with the clock moved). Local runner and puppeteer scripts: `~/github/scopes/who-said-it-shots`.
