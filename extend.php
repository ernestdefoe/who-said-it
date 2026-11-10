<?php

/*
 * Who Said It? A daily quiz made from the forum's own posts.
 *
 * Once a day (the scheduler, or the first page view if it isn't running)
 * Generator picks five quotes from posts a guest can read and gives each
 * three other members with a similar post count. Members get one quote at a
 * time, timed on the server; Quiz checks every answer server-side, and the
 * answer (author, post) is only ever sent for a quote already answered.
 *
 * forum.js only wires the route, the sidebar card, the settings switches and
 * the reminder; the page is a lazy chunk.
 */

use ErnestDefoe\WhoSaidIt\Api;
use ErnestDefoe\WhoSaidIt\Board;
use ErnestDefoe\WhoSaidIt\Forget;
use ErnestDefoe\WhoSaidIt\Console\MakeCommand;
use ErnestDefoe\WhoSaidIt\ForumState;
use ErnestDefoe\WhoSaidIt\Gdpr\WhoSaidItData;
use ErnestDefoe\WhoSaidIt\Http\PageContent;
use ErnestDefoe\WhoSaidIt\Notification\ReminderBlueprint;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Settings\Event\Saved;
use Flarum\User\Event\Deleting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the page is a chunk in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/who-said-it', 'who-said-it', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/who-said-it', 'who-said-it.page', Api\PageController::class)
        ->post('/who-said-it/next', 'who-said-it.next', Api\NextController::class)
        ->post('/who-said-it/answer', 'who-said-it.answer', Api\AnswerController::class)
        ->post('/who-said-it/me', 'who-said-it.me', Api\MeController::class)
        ->get('/who-said-it/admin', 'who-said-it.admin', Api\AdminController::class)
        ->post('/who-said-it/admin', 'who-said-it.admin.make', Api\AdminController::class),

    (new Extend\Console())
        ->command(MakeCommand::class)
        ->schedule(MakeCommand::class, function (ScheduledEvent $event): void {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),

    // Opt-in: the member turns the reminder on from the quiz page or their settings.
    (new Extend\Notification())
        ->type(ReminderBlueprint::class, ['alert']),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('whoSaidIt')
                ->visible(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW))
                ->get(fn ($forum, Context $context) => resolve(ForumState::class)($context->getActor())),
        ]),

    (new Extend\Settings())
        ->default(Settings::NAV, Settings::DEFAULTS[Settings::NAV])
        ->default(Settings::MIN_AGE, Settings::DEFAULTS[Settings::MIN_AGE])
        ->default(Settings::MIN_LENGTH, Settings::DEFAULTS[Settings::MIN_LENGTH])
        ->default(Settings::REPEAT_DAYS, Settings::DEFAULTS[Settings::REPEAT_DAYS])
        ->default(Settings::ACTIVE_DAYS, Settings::DEFAULTS[Settings::ACTIVE_DAYS])
        ->default(Settings::TAGS, Settings::DEFAULTS[Settings::TAGS])
        ->default(Settings::GROUPS, Settings::DEFAULTS[Settings::GROUPS])
        ->default(Settings::TIMEZONE, Settings::DEFAULTS[Settings::TIMEZONE])
        ->serializeToForum('whoSaidItNav', Settings::NAV, 'boolval'),

    // Before the user row goes: their answers go with it (foreign keys), but their quoted words must too.
    // 🚨 Forget, not the GDPR data type: that class extends flarum/gdpr's, which may not be installed.
    (new Extend\Event())
        ->listen(Deleting::class, fn (Deleting $event) => resolve(Forget::class)($event->user->id))
        // A change to who's left out redraws yesterday's answers (today's are checked on every view).
        ->listen(Saved::class, function (Saved $event) {
            if (array_key_exists(Settings::GROUPS, $event->settings)) {
                resolve(Board::class)->forgetYesterday();
            }
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(WhoSaidItData::class),
        ]),
];
