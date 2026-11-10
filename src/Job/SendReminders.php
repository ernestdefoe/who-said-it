<?php

namespace ErnestDefoe\WhoSaidIt\Job;

use Carbon\Carbon;
use ErnestDefoe\WhoSaidIt\Day;
use ErnestDefoe\WhoSaidIt\Notification\ReminderBlueprint;
use ErnestDefoe\WhoSaidIt\Player;
use ErnestDefoe\WhoSaidIt\Score;
use ErnestDefoe\WhoSaidIt\Settings;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * Tells each member who turned the reminder on that today's quiz is up —
 * unless they've already started it, can't play, or are suspended. The
 * notification's subject is the member themself, so syncing it again (a
 * retried job) never sends twice.
 */
class SendReminders extends AbstractJob
{
    public function __construct(private readonly int $dayId)
    {
    }

    public function handle(NotificationSyncer $notifications): void
    {
        $day = Day::query()->find($this->dayId);
        if (! $day) {
            return;
        }

        User::query()
            ->whereIn('id', Player::query()->select('user_id')->where('remind', true))
            ->whereNotIn('id', Score::query()->select('user_id')->where('day_id', $day->id))
            ->with('groups')
            ->chunkById(200, function ($users) use ($notifications, $day) {
                foreach ($users as $user) {
                    /** @var User $user */
                    $suspended = $user->getAttribute('suspended_until');
                    if (($suspended && Carbon::parse($suspended)->isFuture()) || ! $user->can(Settings::PLAY)) {
                        continue;
                    }
                    $notifications->sync(new ReminderBlueprint($user, $day->day, $day->question_count), [$user]);
                }
            });
    }
}
