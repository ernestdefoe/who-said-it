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
 *
 * Members' all-time records (the all-time board, their own reminder and
 * opt-out) are a JSON:API resource, `who-said-it-players`. Playing is a few
 * plain endpoints under /api/who-said-it ON PURPOSE: a quote's answer may
 * only leave the server after that member has answered it, and the quiz's
 * clock starts when a quote is served. A JSON:API resource for quotes would
 * let an include or a filter walk around that gate, and an answer isn't a
 * resource but an action that is checked, timed and scored once. The admin
 * endpoint is an action too (make today's quiz) plus a status summary.
 */

use ErnestDefoe\WhoSaidIt\Api;
use ErnestDefoe\WhoSaidIt\Console\MakeCommand;
use ErnestDefoe\WhoSaidIt\ForumState;
use ErnestDefoe\WhoSaidIt\Gdpr\ForgetErased;
use ErnestDefoe\WhoSaidIt\Gdpr\WhoSaidItData;
use ErnestDefoe\WhoSaidIt\Http\PageContent;
use ErnestDefoe\WhoSaidIt\Listener\ForgetDeleted;
use ErnestDefoe\WhoSaidIt\Listener\RedrawYesterday;
use ErnestDefoe\WhoSaidIt\Notification\ReminderBlueprint;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Events\Erased;
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

    new Extend\ApiResource(Api\Resource\PlayerResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('whoSaidIt')
                ->visible(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW))
                ->get(fn ($forum, Context $context) => resolve(ForumState::class)($context->getActor())),
        ]),

    // 🚨 String keys, not Settings::… constants: extend.php runs while composer may be swapping
    // the package, and autoloading one of our classes here would fatal every request mid-deploy.
    (new Extend\Settings())
        ->default('ernestdefoe-who-said-it.nav', true)
        ->default('ernestdefoe-who-said-it.min_age_days', 7)
        ->default('ernestdefoe-who-said-it.min_length', 60)
        ->default('ernestdefoe-who-said-it.repeat_days', 365)
        ->default('ernestdefoe-who-said-it.active_days', 90)
        ->default('ernestdefoe-who-said-it.tags', '[]')
        ->default('ernestdefoe-who-said-it.never_quote_groups', '[]')
        ->default('ernestdefoe-who-said-it.timezone', 'UTC')
        ->serializeToForum('whoSaidItNav', 'ernestdefoe-who-said-it.nav', 'boolval'),

    // 🚨 Forget, not the GDPR data type: that class extends flarum/gdpr's, which may not be installed.
    (new Extend\Event())
        ->listen(Deleting::class, ForgetDeleted::class)
        ->listen(Saved::class, RedrawYesterday::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(WhoSaidItData::class),
            (new Extend\Event())->listen(Erased::class, ForgetErased::class),
        ]),
];
